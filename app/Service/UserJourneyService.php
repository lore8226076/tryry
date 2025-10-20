<?php

namespace App\Service;

use App\Models\GddbSurgameJourney;
use App\Models\GddbSurgameJourneyReward;
use App\Models\UserItemLogs;
use App\Models\UserJourneyRecord;
use App\Models\UserJourneyRewardMap;
use App\Models\Users;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UserJourneyService
{
    /**
     * 更新或建立玩家章節進度
     *
     * @param  int  $uid  玩家 UID
     * @param  int  $chapterId  章節編號（允許 unique_id 或資料表 id）
     * @param  int  $wave  最新波次
     */
    public function updateJourneyProgress(int $uid, int $chapterId, int $wave): array
    {
        $journey = $this->findJourneyByIdentifier($chapterId);

        if (! $journey) {
            throw new \InvalidArgumentException('指定的章節不存在');
        }

        return DB::transaction(function () use ($uid, $journey, $wave) {
            $record = UserJourneyRecord::firstOrNew(['uid' => $uid]);

            if (! $record->exists) {
                // 第一次建立時補上預設值
                $record->current_journey_id = 1;
                $record->current_wave = 0;
                $record->total_stars = 0;
            }

            $record->current_journey_id = (int) $journey->unique_id;
            $record->current_wave = max(0, $wave);
            $record->save();

            return [
                'chapter_id' => (int) $record->current_journey_id ?? 1,
                'wave' => (int) $record->current_wave,
            ];
        });
    }

    /**
     * 取得玩家目前章節進度
     *
     * @param  int  $uid  玩家 UID
     */
    public function getCurrentProgress(int $uid): array|object
    {
        $record = UserJourneyRecord::where('uid', $uid)->first();

        if (! $record) {
            return (object) [];
        }

        return [
            'chapter_id' => (int) $record->current_journey_id ?? 1,
            'wave' => (int) $record->current_wave ?? 0,
        ];
    }

    /**
     * 取得指定玩家的章節獎勵資訊
     *
     * @param  int  $uid  玩家 UID7
     * @param  int|null  $chapterId  指定章節（可選，預設取玩家目前章節）
     */
    public function getChapterRewards(int $uid, ?int $chapterId = null): array
    {
        $record = UserJourneyRecord::where('uid', $uid)->first();

        if (! $record && ! $chapterId) {
            return [];
        }

        $currentWave = $record?->current_wave ?? 0;
        $currentJourneyId = $record?->current_journey_id ?? 0;

        // 如果有指定章節，只撈該章節；否則撈所有章節
        $query = GddbSurgameJourneyReward::query()
            ->with('journey')
            ->orderBy('journey_id')
            ->orderBy('wave');

        if ($chapterId) {
            $journey = $this->findJourneyByIdentifier($chapterId);
            if (! $journey) {
                return [];
            }
            $query->where('journey_id', $journey->id);
        }

        $rewardList = $query->get();

        if ($rewardList->isEmpty()) {
            return [];
        }

        // 撈出玩家已領取的 reward
        $claimedMap = UserJourneyRewardMap::query()
            ->where('uid', $uid)
            ->whereIn('reward_id', $rewardList->pluck('id'))
            ->pluck('is_received', 'reward_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();

        $rewards = [];

        foreach ($rewardList as $reward) {
            $rewardId = (int) $reward->id;
            $wave = (int) $reward->wave;
            $chapterUid = $reward->journey->unique_id ?? 0;
            $isClaimed = (int) ($claimedMap[$rewardId] ?? 0);

            // 判斷是否解鎖
            if ($chapterUid < $currentJourneyId) {
                $isUnlocked = 1;
            } elseif ($chapterUid == $currentJourneyId) {
                $isUnlocked = $currentWave >= $wave ? 1 : 0;
            } else {
                $isUnlocked = 0;
            }

            // 是否可領取
            $canClaim = 0;
            if ($isUnlocked && ! $isClaimed) {
                $canClaim = $this->canClaimReward($reward->journey_id, $wave, $uid) ? 1 : 0;
            }

            $rewards[] = [
                'chapter_id' => $chapterUid,
                'wave' => $wave,
                'is_unlocked' => $isUnlocked,
                'is_claimed' => $isClaimed,
                'can_claim' => $canClaim,
                'rewards' => $this->formatRewards($reward->rewards),
            ];
        }

        return $rewards;
    }

    /**
     * 領取符合條件的章節獎勵
     *
     * @param  int  $uid  玩家 UID
     * @param  int  $chapterId  章節編號（允許 unique_id 或主鍵）
     */
    public function claimChapterReward(int $uid, int $chapterId): array
    {
        $journey = $this->findJourneyByIdentifier($chapterId);

        if (! $journey) {
            throw new \RuntimeException('JourneyReward:0001');
        }

        return DB::transaction(function () use ($uid, $journey) {
            $record = UserJourneyRecord::where('uid', $uid)->lockForUpdate()->first();

            if (! $record) {
                throw new \RuntimeException('JourneyReward:0003');
            }

            $playerChapterId = (int) $record->current_journey_id;
            $targetChapterId = (int) $journey->unique_id;

            if ($playerChapterId < $targetChapterId) {
                throw new \RuntimeException('JourneyReward:0002');
            }

            $availableWave = (int) $record->current_wave;
            $isCurrentChapter = $playerChapterId === $targetChapterId;

            $rewardCandidates = GddbSurgameJourneyReward::query()
                ->where('journey_id', $journey->id)
                ->when($isCurrentChapter, function ($query) use ($availableWave) {
                    $query->where('wave', '<=', $availableWave);
                })
                ->orderBy('wave')
                ->get();

            if ($rewardCandidates->isEmpty()) {
                throw new \RuntimeException('JourneyReward:0002');
            }

            $claimedMap = UserJourneyRewardMap::query()
                ->where('uid', $uid)
                ->whereIn('reward_id', $rewardCandidates->pluck('id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('reward_id');

            $claimableRewards = [];

            foreach ($rewardCandidates as $candidate) {
                $claimed = $claimedMap->get($candidate->id);

                if (! $claimed || (int) $claimed->is_received !== 1) {
                    $claimableRewards[] = $candidate;
                }
            }

            if (empty($claimableRewards)) {
                throw new \RuntimeException('JourneyReward:0004');
            }

            $aggregatedRewards = [];
            $claimedWaves = [];

            foreach ($claimableRewards as $reward) {
                $claimedWaves[] = (int) $reward->wave;

                foreach ($this->formatRewards($reward->rewards) as $item) {
                    $itemId = (int) ($item['item_id'] ?? 0);
                    $amount = (int) ($item['amount'] ?? 0);

                    if ($itemId <= 0 || $amount <= 0) {
                        continue;
                    }

                    if (! isset($aggregatedRewards[$itemId])) {
                        $aggregatedRewards[$itemId] = 0;
                    }

                    $aggregatedRewards[$itemId] += $amount;
                }
            }

            $finalRewards = [];

            foreach ($aggregatedRewards as $itemId => $amount) {
                $finalRewards[] = [
                    'item_id' => (int) $itemId,
                    'amount' => (int) $amount,
                ];
            }

            $deliveredList = $this->grantRewardsToUser($uid, $finalRewards, '冒險章節獎勵領取');

            foreach ($claimableRewards as $reward) {
                UserJourneyRewardMap::updateOrCreate(
                    [
                        'uid' => $uid,
                        'reward_id' => (int) $reward->id,
                    ],
                    [
                        'is_received' => 1,
                    ]
                );
            }

            sort($claimedWaves);

            return [
                'chapter_id' => (int) $journey->unique_id,
                'reward_status' => 1,
                'claimed_wave' => array_values(array_unique($claimedWaves)),
                'rewards' => $deliveredList,
            ];
        });
    }

    /**
     * 玩家道具取得
     */
    public function claimReward($user, $rewards = [])
    {
        return DB::transaction(function () use ($user, $rewards) {
            if (empty($rewards)) {
                return;
            }
            foreach ($rewards as $reward) {
                $itemId = $reward['item_id'];
                $amount = $reward['amount'];
                $result = UserItemService::addItem(
                    UserItemLogs::TYPE_SYSTEM,
                    $user->id,
                    $user->uid,
                    $itemId,
                    $amount,
                    1,
                    '主線關卡掉落獎勵領取'
                );
                if (($result['success'] ?? 0) !== 1) {
                    throw new \RuntimeException('UserItem:0002');
                }
            }
        });
    }

    /**
     * 標記章節獎勵已領取
     *
     * @param  int  $uid  玩家 UID
     * @param  int  $rewardId  章節獎勵 ID
     */
    public function markChapterRewardClaimed(int $uid, int $rewardId): bool
    {
        $reward = GddbSurgameJourneyReward::find($rewardId);

        if (! $reward) {
            return false;
        }

        return (bool) UserJourneyRewardMap::query()->updateOrCreate([
            'uid' => $uid,
            'reward_id' => $reward->id,
        ], [
            'is_received' => 1,
        ]);
    }

    /**
     * 同步玩家章節累積星數
     *
     * @param  int  $uid  玩家 UID
     * @param  int  $totalStars  最新星數
     */
    public function syncTotalStars(int $uid, int $totalStars): void
    {
        $record = UserJourneyRecord::firstOrNew(['uid' => $uid]);

        if (! $record->exists) {
            $record->current_journey_id = 0;
            $record->current_wave = 0;
        }

        $record->total_stars = max(0, $totalStars);
        $record->save();
    }

    /**
     * 從章節與波次取得 rewardId
     */
    public function getRewardIdByChapterAndWave(int $chapterId, int $wave): ?int
    {
        $journey = $this->findJourneyByIdentifier($chapterId);
        if (! $journey) {
            return null;
        }

        $reward = GddbSurgameJourneyReward::where('journey_id', $journey->id)
            ->where('wave', $wave)
            ->first();

        return $reward ? (int) $reward->id : null;
    }

    /**
     * 檢查是否能領取 reward（必須按順序，跨章節）
     */
    public function canClaimReward(int $chapterId, int $wave, int $uid): bool
    {
        $journey = $this->findJourneyByIdentifier($chapterId);
        if (! $journey) {
            return false;
        }

        // 撈該 reward
        $reward = GddbSurgameJourneyReward::whereHas('journey', function ($q) use ($chapterId) {
            $q->where('unique_id', $chapterId);
        })
            ->where('wave', $wave)
            ->first();

        if (! $reward) {
            return false;
        }

        // 檢查是否已領取
        $claimed = UserJourneyRewardMap::where('uid', $uid)
            ->where('reward_id', $reward->id)
            ->exists();

        return ! $claimed;
    }

    /**
     * 取得玩家目前累積星數
     *
     * @param  int  $uid  玩家 UID
     */
    public function getTotalStars(int $uid): int
    {
        return (int) UserJourneyRecord::where('uid', $uid)->value('total_stars');
    }

    /**
     * 依照章節編號搜尋資料
     *
     * @param  int  $identifier  unique_id 或主鍵 id
     */
    public function findJourneyByIdentifier(int $identifier): ?GddbSurgameJourney
    {
        return GddbSurgameJourney::where('unique_id', $identifier)
            ->orWhere('id', $identifier)
            ->first();
    }

    /**
     * 將獎勵字串轉換為統一格式
     *
     * @param  mixed  $rawRewards  獎勵原始資料
     */
    public function formatRewards(mixed $rawRewards): array
    {
        if (empty($rawRewards)) {
            return [];
        }

        $decoded = null;

        if (is_string($rawRewards)) {
            $trimmed = trim($rawRewards);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = json_decode(str_replace("'", '"', $trimmed), true);
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = $this->parseRewardPairs($trimmed);
            }
        } elseif (is_array($rawRewards)) {
            $decoded = $rawRewards;
        }

        if (! is_array($decoded)) {
            return [];
        }

        $rewards = [];

        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $itemId = Arr::get($entry, 'item_id', Arr::get($entry, 'ItemID'));
                $amount = Arr::get($entry, 'amount', Arr::get($entry, 'Amount'));

                if ($itemId !== null && $amount !== null) {
                    $rewards[] = [
                        'item_id' => (int) $itemId,
                        'amount' => (int) $amount,
                    ];

                    continue;
                }

                if (isset($entry[0], $entry[1])) {
                    $rewards[] = [
                        'item_id' => (int) $entry[0],
                        'amount' => (int) $entry[1],
                    ];
                }
            }
        }

        return $rewards;
    }

    /**
     * 解析簡單的 item/amount 字串格式
     *
     * @param  string  $value  原始字串
     */
    protected function parseRewardPairs(string $value): array
    {
        $pairs = [];

        foreach (preg_split('/[|;\n]+/', $value) as $segment) {
            $segment = trim($segment, "[]{}() \t");

            if ($segment === '') {
                continue;
            }

            if (preg_match_all('/(\d+)\D+(\d+)/', $segment, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $pairs[] = [
                        (int) $match[1],
                        (int) $match[2],
                    ];
                }

                continue;
            }

            $parts = preg_split('/[,:\s]+/', $segment);
            $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

            if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $pairs[] = [(int) $parts[0], (int) $parts[1]];
            }
        }

        return $pairs;
    }

    /**
     * 發送指定獎勵給玩家
     *
     * @param  int  $uid  玩家 UID
     * @param  array  $rewards  獎勵內容
     * @param  string  $memo  發放備註
     */
    public function grantRewardsToUser(int $uid, array $rewards, string $memo): array
    {
        $user = Users::where('uid', $uid)->first();

        if (! $user) {
            throw new \RuntimeException('AUTH:0006');
        }

        $finalRewards = [];

        foreach ($rewards as $reward) {
            $itemId = (int) ($reward['item_id'] ?? 0);
            $amount = (int) ($reward['amount'] ?? 0);

            if ($itemId <= 0 || $amount <= 0) {
                continue;
            }

            $result = UserItemService::addItem(
                UserItemLogs::TYPE_SYSTEM,
                $user->id,
                $uid,
                $itemId,
                $amount,
                1,
                $memo
            );

            if (($result['success'] ?? 0) !== 1) {
                $errorCode = $result['error_code'] ?? 'UserItem:0002';
                throw new \RuntimeException($errorCode);
            }

            $finalRewards[] = [
                'item_id' => isset($result['item_id']) ? (int) $result['item_id'] : $itemId,
                'amount' => isset($result['qty']) ? (int) $result['qty'] : $amount,
            ];
        }

        return $finalRewards;

    }

    /**
     * 重置玩家章節進度與獎勵狀態
     *
     * @param  int  $uid  玩家 UID
     */
    public function resetJourneyRewards(int $uid): void
    {
        DB::transaction(function () use ($uid) {
            UserJourneyRecord::where('uid', $uid)->delete();
            UserJourneyRewardMap::where('uid', $uid)->delete();
        });
    }
}
