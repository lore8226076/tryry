<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GddbSurgamePassiveReward as Rewards;
use App\Models\UserPatrolReward;
use App\Models\Users;
use App\Models\UserSurGameInfo;
use App\Service\CharacterService;
use App\Service\ErrorService;
use App\Service\StaminaService;
use App\Service\UserItemService;
use App\Service\UserJourneyService;
use App\Service\TaskService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatrolController extends Controller
{
    private int $rewardInterval;

    private int $quickCost = 15; // 快速巡邏消耗體力

    protected $userJourneyService;

    public function __construct(Request $request, UserJourneyService $userJourneyService)
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $this->userJourneyService = $userJourneyService;
        $this->rewardInterval = 1;
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => []]);
        }
    }

    // 玩家領取巡邏獎勵
    public function claim(Request $request)
    {
        $uid = auth()->guard('api')?->user()?->uid;
        $now = Carbon::now();

        // 檢查玩家是否存在
        $user = Users::where('uid', $uid)->first();
        if (! $user || empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 401);
        }

        // 檢查surgame資料是否存在
        $surgameInfo = UserSurGameInfo::where('uid', $uid)->first();
        if (! $surgameInfo) {
            $surgameInfo = UserSurGameInfo::createInitialData($uid);
        }

        // 取得玩家當前進度
        $currentChapterId = 1;
        $currentChapterData = $this->userJourneyService->getCurrentProgress($user->uid);
        if ($currentChapterData) {
            $currentChapterId = $currentChapterData['chapter_id'];
        }

        return DB::transaction(function () use ($uid, $now, $user, $currentChapterId) {

            $userReward = UserPatrolReward::lockForUpdate()->firstOrCreate(
                ['uid' => $uid],
                ['last_claimed_at' => $now, 'pending_minutes' => 0]
            );

            $isFirstClaim = $userReward->wasRecentlyCreated;

            $lastClaimTime = Carbon::parse($userReward->last_claimed_at);
            $diffMinutes = $lastClaimTime->diffInMinutes($now);

            $totalMinutes = $diffMinutes + $userReward->pending_minutes;
            $totalMinutes = min($totalMinutes, 24 * 60); // 最多 24 小時

            // 計算可領分鐘數
            $effectiveMinutes = floor($totalMinutes / $this->rewardInterval) * $this->rewardInterval;
            $pendingMinutes = $totalMinutes % $this->rewardInterval;

            // 第一次領至少一段
            if ($isFirstClaim) {
                $effectiveMinutes = max($effectiveMinutes, $this->rewardInterval);
                $pendingMinutes = 0;
            }

            // 不足一段不可領
            if ($effectiveMinutes < $this->rewardInterval) {
                $userReward->pending_minutes = $pendingMinutes;
                $userReward->save();

                $error = ErrorService::errorCode(__METHOD__, 'PATROL:0001');
                $error['data']['last_claimed_at'] = $userReward->last_claimed_at;

                return response()->json($error, 422);
            }

            // 計算獎勵
            [$finalRewards, $pendingMinutes] = $this->calculateRewards(
                $currentChapterId,
                $effectiveMinutes,
                $this->rewardInterval
            );

            foreach ($finalRewards as $itemId => $amount) {
                UserItemService::addItem('60', $user->id, $user->uid, $itemId, $amount, 1, '巡邏任務獎勵');
            }

            $userReward->last_claimed_at = $now;
            $userReward->pending_minutes = $pendingMinutes;
            $userReward->save();

            $syncResult = CharacterService::syncMainCharacter($user);
            // if ($syncResult['success'] == false) {
            //     return response()->json([
            //         ErrorService::errorCode(__METHOD__, $syncResult['error_code']),
            //     ], 500);
            // }

            return response()->json([
                'data' => [
                    'message' => 'success',
                    'rewards' => collect($finalRewards)->map(fn ($amount, $itemId) => [
                        'item_id' => $itemId,
                        'amount' => $amount,
                    ])->values(),
                    'last_claimed_at' => $userReward->last_claimed_at,
                    'level_up_state' => [
                        'has_level_up' => $syncResult['success'] ? 1 : 0,
                        'level_reward' => $syncResult['reward'] ?? [],
                    ],
                ],
            ], 200);
        });
    }

    public function quickPatorl(Request $request)
    {
        $uid = auth()->guard('api')?->user()?->uid;

        // 檢查玩家是否存在
        $user = Users::where('uid', $uid)->first();
        if (! $user || empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 401);
        }

        // 檢查surgame資料是否存在
        $surgameInfo = UserSurGameInfo::where('uid', $uid)->first();
        if (! $surgameInfo) {
            $surgameInfo = UserSurGameInfo::createInitialData($uid);
        }

        // 扣除體力
        $staminaResult = StaminaService::deductStamina($uid, $this->quickCost, '快速巡邏');
        if (empty($staminaResult['success'])) {
            return response()->json(ErrorService::errorCode(__METHOD__, $staminaResult['error_code']), 422);
        }

        // 取得玩家當前進度
        $currentChapterId = 1;
        $currentChapterData = $this->userJourneyService->getCurrentProgress($uid);
        if ($currentChapterData) {
            $currentChapterId = $currentChapterData['chapter_id'];
        }

        return DB::transaction(function () use ($user, $currentChapterId) {
            $effectiveMinutes = 24 * 60;

            // 計算獎勵
            [$finalRewards, $pendingMinutes] = $this->calculateRewards(
                $currentChapterId,
                $effectiveMinutes
            );

            // 更新玩家物品
            foreach ($finalRewards as $itemId => $amount) {
                UserItemService::addItem('60', $user->id, $user->uid, $itemId, $amount, 1, '快速巡邏任務獎勵');
            }

            // 玩家等級更新
            $syncResult = CharacterService::syncMainCharacter($user);
            // 任務Service
            $taskService = new TaskService;
            // 本次登入是否有完成任務
            $completedTask = $taskService->getCompletedTasks($user->uid);
            $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);

            return response()->json([
                'data' => [
                    'message' => 'success',
                    'rewards' => collect($finalRewards)->map(fn ($amount, $itemId) => [
                        'item_id' => $itemId,
                        'amount' => $amount,
                    ])->values(),
                    'level_up_state' => [
                        'has_level_up' => $syncResult['success'] ? 1 : 0,
                        'level_reward' => $syncResult['reward'] ?? [],
                    ],
                    'finishedTask' => $formattedTaskResult,
                ],
            ], 200);
        });

    }

    /**
     * 計算固定與隨機獎勵
     */
    private function calculateRewards(int $nowStage, int $effectiveMinutes, int $interval = 10): array
    {
        $stageRewards = Rewards::where('now_stage', $nowStage)->first();
        if (! $stageRewards) {
            return [[], 0];
        }

        $hourCoin = $stageRewards->hour_coin;
        $hourExp = $stageRewards->hour_exp;
        $hourCrystal = $stageRewards->hour_crystal;
        $hourPaint = $stageRewards->hour_paint;
        $hourXp = $stageRewards->hour_xp;

        // 計算幾段完整獎勵
        $totalSegments = intdiv($effectiveMinutes, $interval);
        $pendingMinutes = $effectiveMinutes % $interval;

        $finalRewards = [];

        // 固定獎勵按比例
        $rewardMap = [
            101 => $hourCoin,
            199 => $hourExp,
            198 => $hourCrystal,
            191 => $hourPaint,
            190 => $hourXp,
        ];

        foreach ($rewardMap as $itemId => $perHour) {
            $perInterval = floor(($perHour / 60) * $interval);
            $amount = $perInterval * $totalSegments;
            if ($amount > 0) {
                $finalRewards[$itemId] = ($finalRewards[$itemId] ?? 0) + $amount;
            }
        }

        // 隨機獎勵每段抽一次
        $bonusPool = $stageRewards->rand_reward;
        if (empty($bonusPool)) {
            return [$finalRewards, $pendingMinutes];
        } else {
            if (is_string($bonusPool)) {
                $bonusPool = json_decode($bonusPool, true) ?? [];
            }

            for ($i = 0; $i < $totalSegments; $i++) {
                foreach ($bonusPool as [$itemId, $amount, $chance]) {
                    if (mt_rand(1, 100) <= $chance) {
                        $finalRewards[$itemId] = ($finalRewards[$itemId] ?? 0) + $amount;
                    }
                }
            }
        }

        return [$finalRewards, $pendingMinutes];
    }
}
