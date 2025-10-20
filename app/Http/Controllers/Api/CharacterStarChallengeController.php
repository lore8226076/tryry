<?php

namespace App\Http\Controllers\Api;

use App\Service\ErrorService;
use App\Service\UserJourneyService;
use App\Service\StaminaService;
use App\Service\UserJourneyChallengeService;
use App\Service\TaskService;
use Illuminate\Http\Request;

class CharacterStarChallengeController extends Controller
{
    protected $challengeService;
    protected $journeyService;

    public function __construct(UserJourneyService $journeyService,UserJourneyChallengeService $challengeService, Request $request)
    {
        $this->challengeService = $challengeService;
        $this->journeyService = $journeyService;

        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);

        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {

            $this->middleware('auth:api', ['except' => ['update', 'progress', 'rewards', 'claimReward']]);
        }
    }

    /**
     *  進入主線扣除體力
     */
    public function deduct(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $staminaResult = StaminaService::deductStamina($uid, 5, '星級關卡挑戰');
        if (empty($staminaResult['success'])) {
            return response()->json(ErrorService::errorCode(__METHOD__, $staminaResult['error_code']), 422);
        }
        $stamina = StaminaService::getStamina($uid);

          // 任務Service
        $taskService = new TaskService();
          // 本次登入是否有完成任務
        $completedTask       = $taskService->getCompletedTasks($uid);
        $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);


        return response()->json(['data' => ['success' => true, 'stamina' => $stamina, 'finishedTask' => $formattedTaskResult]]);
    }

    /**
     * 更新玩家星級挑戰
     */
    public function update(Request $request)
    {
        $user = $this->resolveUid($request, true);
        $uid = $user?->uid;
        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $chapterId = $request->input('chapter_id', 1);
        $earnedStars = $this->normalizeEarnedStars($request->input('earned_stars'));

        // 隨機掉落物
        $items = $request->input('drop_items', []);
        if (is_string($items)) {
            $items = json_decode($items, true);
        }

        if (! is_numeric($chapterId) || (int) $chapterId <= 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'Journey:0001'), 422);
        }

        if (empty($earnedStars)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'StarChallenge:0001'), 422);
        }

        try {
            $progress = $this->challengeService->updateChallengeProgress(
                $uid,
                (int) $chapterId,
                $earnedStars
            );

            $claim = $this->journeyService->claimReward($user, $items);
            $result = $progress;
            $result['rewards'] = $items;
        } catch (\InvalidArgumentException $exception) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'StarChallenge:0002'), 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * 取得玩家星級挑戰進度
     */
    public function progress(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $progress = $this->challengeService->getChallengeProgress($uid);

        return response()->json(['data' => $progress]);
    }

    /**
     * 取得玩家星級挑戰獎勵列表
     */
    public function rewards(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $rewards = $this->challengeService->getChallengeRewards($uid);

        return response()->json(['data' => $rewards]);
    }

    /**
     * 領取星級挑戰獎勵
     */
    public function claimReward(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $rewardUniqueId = $request->input('reward_id');

        if (! is_numeric($rewardUniqueId) || (int) $rewardUniqueId <= 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'StarReward:0005'), 422);
        }

        try {
            $result = $this->challengeService->claimStarReward($uid, (int) $rewardUniqueId);
        } catch (\RuntimeException $exception) {
            $code = $exception->getMessage();

            if (is_string($code) && strpos($code, ':') !== false) {
                return response()->json(ErrorService::errorCode(__METHOD__, $code), 422);
            }

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 422);
        } catch (\Throwable $throwable) {
            \Log::error('星級獎勵領取失敗', [
                'uid' => $uid,
                'reward_unique_id' => $rewardUniqueId,
                'message' => $throwable->getMessage(),
            ]);

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * 解析請求來源的 UID
     */
    protected function resolveUid(Request $request, $getUser = false)
    {
        $authUser = auth()->guard('api')->user();
        if ($getUser) {
            return $authUser;
        }

        if ($authUser?->uid) {
            return (int) $authUser->uid;
        }

        $uid = $request->input('uid', $request->query('uid'));

        return is_numeric($uid) ? (int) $uid : null;
    }

    /**
     * 將星級資料調整成整數陣列
     */
    protected function normalizeEarnedStars($input): array
    {
        if (is_string($input)) {
            $trimmed = trim($input);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $input = $decoded;
            } else {
                $input = preg_split('/[\s,]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        if (! is_array($input)) {
            return [];
        }

        $stars = [];

        foreach ($input as $value) {
            if (is_bool($value)) {
                $stars[] = $value ? 1 : 0;

                continue;
            }

            if (is_numeric($value)) {
                $stars[] = (int) $value;
            }
        }

        return $stars;
    }

    // 重置uid章節與領獎狀態
    public function resetProgress(Request $request)
    {

        // 僅允許測試環境
        $allowedUrls = ['https://project_ai.jengi.tw/api',
            'https://localhost/api',
            'https://laravel.test/api',
            'https://clang-party-dev.wow-dragon.com.tw/api',
            'https://clang_party_dev.wow-dragon.com.tw/api',
            'https://clang-party-qa.wow-dragon.com.tw/api',
        ];

        if (! in_array(config('services.API_URL'), $allowedUrls)) {
            return response()->json(['message' => '限制測試環境使用'], 403);
        }

        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        try {
            $this->challengeService->resetChallengeProgress($uid);
        } catch (\Throwable $throwable) {
            \Log::error('星級獎勵重置失敗', [
                'uid' => $uid,
                'message' => $throwable->getMessage(),
            ]);

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 422);
        }

        return response()->json(['data' => ['success' => true]]);
    }
}
