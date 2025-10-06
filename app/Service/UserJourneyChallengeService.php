<?php

namespace App\Service;

use App\Models\GddbSurgameJourneyStarReward;
use App\Models\UserJourneyStarChallenge;
use App\Models\UserJourneyStarRewardMap;
use Illuminate\Support\Facades\DB;

class UserJourneyChallengeService
{
    protected $journeyService;

    public function __construct(UserJourneyService $journeyService)
    {
        $this->journeyService = $journeyService;
    }

    /**
     * 更新玩家星級挑戰進度
     *
     * @param  int  $uid  玩家 UID
     * @param  int  $chapterId  章節編號（允許 unique_id 或資料表 id）
     * @param  array  $earnedStars  本次取得的星星資訊
     */
    public function updateChallengeProgress(int $uid, int $chapterId, array $earnedStars): array
    {
        $journey = $this->journeyService->findJourneyByIdentifier($chapterId);

        if (! $journey) {
            throw new \InvalidArgumentException('指定的章節不存在');
        }

        $newMask = $this->buildStarMask($earnedStars);

        return DB::transaction(function () use ($uid, $journey, $newMask) {
            $progress = UserJourneyStarChallenge::query()
                ->where('uid', $uid)
                ->where('challenge_id', $journey->unique_id)
                ->lockForUpdate()
                ->first();

            if (! $progress) {
                $progress = new UserJourneyStarChallenge([
                    'uid' => $uid,
                    'challenge_id' => $journey->unique_id,
                    'stars_mask' => 0,
                ]);
            }

            if ($newMask > 0) {
                $progress->stars_mask |= $newMask;
            }

            $progress->save();

            $totalStars = $this->calculateTotalStars($uid);
            $this->journeyService->syncTotalStars($uid, $totalStars);

            return [
                'chapter_id' => (int) $progress->challenge_id,
                'stars_mask' => (int) $progress->stars_mask,
                'stars' => $this->formatStarOutput((int) $progress->stars_mask),
                'stars_total' => $totalStars,
            ];
        });
    }

    /**
     * 取得玩家星級挑戰進度概況
     *
     * @param  int  $uid  玩家 UID
     */
    public function getChallengeProgress(int $uid): array
    {
        $challenges = UserJourneyStarChallenge::where('uid', $uid)->get();

        $chapterInfos = [];
        $totalStars = 0;

        foreach ($challenges as $challenge) {
            $flags = $this->maskToStarFlags((int) $challenge->stars_mask);
            $chapterInfos[] = [
                'chapter_id' => (int) $challenge->challenge_id,
                'stars' => $this->formatStarOutput((int) $challenge->stars_mask),
            ];
            $totalStars += array_sum($flags);
        }
        if (empty($chapterInfos)) $chapterInfos = (object) $chapterInfos;

        return [
            'stars_total' => $totalStars,
            'chapter_informations' => $chapterInfos,
        ];
    }

    /**
     * 取得玩家可領取的星級獎勵
     *
     * @param  int  $uid  玩家 UID
     */
    public function getChallengeRewards(int $uid): array
    {
        $totalStars = $this->journeyService->getTotalStars($uid);
        $rewardList = GddbSurgameJourneyStarReward::query()
            ->orderBy('star_count')
            ->get();

        if ($rewardList->isEmpty()) {
            return (object)[];
        }

        $claimedMap = UserJourneyStarRewardMap::query()
            ->where('uid', $uid)
            ->pluck('is_received', 'reward_unique_id')
            ->map(function ($value) {
                return (int) $value;
            })
            ->toArray();

        $rewards = [];

        foreach ($rewardList as $reward) {
            $uniqueId = (int) $reward->unique_id;
            $isClaimed = $claimedMap[$uniqueId] ?? 0;
            $rewards[] = [
                'unique_id' => $uniqueId,
                'type' => $reward->type,
                'star_count' => (int) $reward->star_count,
                'is_claimed' => (int) $isClaimed,
                'rewards' => $this->journeyService->formatRewards($reward->rewards),
            ];
        }

        return $rewards;
    }

    /**
     * 領取指定的星級挑戰獎勵
     *
     * @param  int  $uid  玩家 UID
     * @param  int  $rewardUniqueId  星級獎勵 unique_id
     */
    public function claimStarReward(int $uid, int $rewardUniqueId): array
    {
        $reward = GddbSurgameJourneyStarReward::where('unique_id', $rewardUniqueId)->first();
        if (! $reward) {
            throw new \RuntimeException('StarReward:0001');
        }

        $totalStars = $this->journeyService->getTotalStars($uid);

        if ($totalStars < (int) $reward->star_count) {
            throw new \RuntimeException('StarReward:0002');
        }

        return DB::transaction(function () use ($uid, $reward) {
            $claimed = UserJourneyStarRewardMap::lockForUpdate()
                ->where('uid', $uid)
                ->where('reward_unique_id', $reward->unique_id)
                ->first();

            if ($claimed && (int) $claimed->is_received === 1) {
                throw new \RuntimeException('StarReward:0003');
            }
            $formattedRewards = $this->journeyService->formatRewards($reward->rewards);
            $deliveredRewards = $this->journeyService->grantRewardsToUser($uid, $formattedRewards, '星級挑戰獎勵領取');

            UserJourneyStarRewardMap::updateOrCreate(
                [
                    'uid' => $uid,
                    'reward_unique_id' => (int) $reward->unique_id,
                ],
                [
                    'is_received' => 1,
                ]
            );

            return [
                'reward_id' => (int) $reward->unique_id,
                'star_count' => (int) $reward->star_count,
                'reward_status' => 1,
                'rewards' => $deliveredRewards,
            ];
        });
    }

    /**
     * 標記星級獎勵已領取
     *
     * @param  int  $uid  玩家 UID
     * @param  int  $rewardUniqueId  星級獎勵 unique_id
     */
    public function markStarRewardClaimed(int $uid, int $rewardUniqueId): bool
    {
        $reward = GddbSurgameJourneyStarReward::where('unique_id', $rewardUniqueId)->first();

        if (! $reward) {
            return false;
        }

        return (bool) UserJourneyStarRewardMap::query()->updateOrCreate([
            'uid' => $uid,
            'reward_unique_id' => (int) $reward->unique_id,
        ], [
            'is_received' => 1,
        ]);

    }

    /**
     * 重置玩家星級挑戰進度與獎勵狀態
     */
    public function resetChallengeProgress(int $uid): void
    {
        DB::transaction(function () use ($uid) {
            UserJourneyStarChallenge::where('uid', $uid)->delete();
            UserJourneyStarRewardMap::where('uid', $uid)->delete();
            $this->journeyService->syncTotalStars($uid, 0);
        });
    }

    /**
     * 將 payload 轉換成星星位元圖
     *
     * @param  array  $earnedStars  取得的星星資訊
     */
    protected function buildStarMask(array $earnedStars): int
    {
        $mask = 0;

        $isBooleanMap = true;
        foreach ($earnedStars as $value) {
            if (! is_numeric($value) || ! in_array((int) $value, [0, 1], true)) {
                $isBooleanMap = false;
                break;
            }
        }

        if ($isBooleanMap) {
            foreach (array_values($earnedStars) as $index => $value) {
                if ((int) $value === 1) {
                    $mask |= 1 << $index;
                }
            }

            return $mask;
        }

        foreach ($earnedStars as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $starIndex = (int) $value;
            if ($starIndex <= 0) {
                continue;
            }

            $mask |= 1 << ($starIndex - 1);
        }

        return $mask;
    }

    /**
     * 將位元圖轉成星星陣列
     *
     * @param  int  $mask  星星位元
     */
    protected function maskToStarFlags(int $mask): array
    {
        $flags = [];

        for ($i = 0; $i < 3; $i++) {
            $flags[$i] = ($mask & (1 << $i)) ? 1 : 0;
        }

        return $flags;
    }

    /**
     * 產生標準化的星級輸出格式
     *
     * @param  int  $mask  星星位元
     */
    protected function formatStarOutput(int $mask): array
    {
        $flags = $this->maskToStarFlags($mask);

        return [
            'star1' => $flags[0] ?? 0,
            'star2' => $flags[1] ?? 0,
            'star3' => $flags[2] ?? 0,
        ];
    }

    /**
     * 計算玩家所有章節的星星總數
     *
     * @param  int  $uid  玩家 UID
     */
    protected function calculateTotalStars(int $uid): int
    {
        return UserJourneyStarChallenge::where('uid', $uid)
            ->get()
            ->sum(function (UserJourneyStarChallenge $challenge) {
                return array_sum($this->maskToStarFlags((int) $challenge->stars_mask));
            });
    }
}
