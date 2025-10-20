<?php

namespace App\Service;

use App\Models\Follows;
use App\Models\UserGachaOrders;
use App\Models\UserItemLogs;
use App\Models\UserItems;
use App\Models\UserLoginLogs;
use App\Models\UserMaps;
use App\Models\UserPayOrders;
use App\Models\UserPet;
use App\Models\Users;
use App\Models\UserStaminaLog;
use App\Models\UserStats;
use App\Models\UserTasks;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserStatsService
{
    protected $taskService;

    protected $keywords;

    protected $calculateStat;

    private $useOwnMethod = false;

    public function __construct($taskService, array $keywords = [], $calculateStat = null)
    {
        $this->taskService = $taskService;
        $this->keywords = ! empty($keywords) ? $keywords : $this->keywords();

        // 如果 calculateStat 是 array，代表是 [$object, 'method']，自動轉成 callable
        if (is_array($calculateStat)) {
            $this->calculateStat = [$calculateStat[0], $calculateStat[1]];
        } elseif (is_callable($calculateStat)) {
            $this->calculateStat = $calculateStat;
        } else {
            // 沒有傳 就用自己的method
            $this->calculateStat = [$this, 'calculateStat'];
            $this->useOwnMethod = true;
        }
    }

    /** 用關鍵字自動更新任務並回傳已完成任務 */
    public function updateByKeyword($user, $keyword, array $onlyColumns = [], $value = null)
    {
        if (isset($this->keywords[$keyword])) {
            $finishedTaskIds = [];
            $finishedTasks = [];

            // 過濾指定欄位（如果有指定）
            $columns = $this->keywords[$keyword];
            if (! empty($onlyColumns)) {
                $columns = array_intersect($columns, $onlyColumns);
            }

            foreach ($columns as $column) {
                // 更新統計資料
                $result = $this->updateUserStats($user, $column, $value);
                $taskIds = $result['taskIds'];

                $progress = ['count' => $result['recordCount']];
                if (! empty($taskIds)) {
                    // 自動更新對應任務資料
                    $result = $this->updateTaskData($user, $taskIds, $progress);
                    $finishedTaskIds = array_merge($finishedTaskIds, $result);
                }
            }
            if (! empty($finishedTaskIds)) {
                $finishedTasks = $this->taskService->getCompletedTasks($user->uid, $finishedTaskIds);
            }

            return $finishedTasks;
        }
    }

    /** 統計資料格式計算 + 回傳符合任務 */
    public function updateUserStats($user, $column, $value = null)
    {

        $recordCount = call_user_func($this->calculateStat, $user->uid, $column, $value);
        if ($recordCount === null) {
            return ['taskIds' => [], 'recordCount' => $recordCount];
        }

        // 更新統計資料
        if ($this->useOwnMethod) {
            $this->updateStatColumn($user->uid, $column, $recordCount);
        }
        // 取得符合條件的任務ID
        $taskIds = $this->getTaskIdsByColumn($column, $user->uid);

        return ['taskIds' => $taskIds, 'recordCount' => $recordCount];
    }

    /** 自動更新對應任務資料 */
    public function updateTaskData($user, $taskIds, $progress)
    {
        $finishedTaskIds = [];
        try {
            if (! empty($taskIds)) {
                // 自動接取對應任務
                if (method_exists($this->taskService, 'autoAssignTasks')) {
                    $this->taskService->autoAssignTasks($user->uid, $taskIds);
                }
                // 提交進度
                foreach ($taskIds as $taskId) {
                    $userTask = $this->taskService->submitProgress($user->uid, $taskId, $progress);
                    if ($userTask->status == 'completed') {
                        $finishedTaskIds[] = $taskId;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('更新玩家任務資料失敗: '.['uid' => $user->uid, 'error' => $e->getMessage()]);
        }

        return $finishedTaskIds;
    }

    /** 計算統計資料 */
    private function calculateStat(string $uid, string $column, $value = null): ?int
    {
        try {
            $user = Users::where('uid', $uid)->first();
            switch ($column) {
                case 'login_total_days': // 登入總天數
                    return UserLoginLogs::select(DB::raw('DATE(created_at) as login_date'))
                        ->where('uid', $uid)
                        ->groupBy(DB::raw('DATE(created_at)'))
                        ->get()
                        ->count();

                case 'login_streak_days': // 登入連續天數

                    // 取得所有登入日期
                    $dates = UserLoginLogs::select(DB::raw('DATE(created_at) as d'))
                        ->where('uid', $uid)
                        ->groupBy(DB::raw('DATE(created_at)'))
                        ->pluck('d')
                        ->map(fn ($d) => Carbon::parse($d)->startOfDay())
                        ->keyBy(fn ($d) => $d->toDateString());

                    // 從今天開始往前推算
                    $cursor = now()->startOfDay();
                    $streak = 0;

                    while ($dates->has($cursor->toDateString())) {
                        $streak++;
                        $cursor->subDay();
                    }

                    return $streak;

                case 'login_streak_max': // 登入連續天數
                    $dates = UserLoginLogs::select(DB::raw('DATE(created_at) as d'))
                        ->where('uid', $uid)
                        ->groupBy(DB::raw('DATE(created_at)'))
                        ->pluck('d')
                        ->map(fn ($d) => Carbon::parse($d)->startOfDay())
                        ->values();

                    $maxStreak = 0;
                    $currentStreak = 0;
                    $prevDate = null;

                    foreach ($dates as $date) {
                        if ($prevDate && $date->diffInDays($prevDate) === 1) {
                            $currentStreak++;
                        } else {
                            $currentStreak = 1;
                        }

                        $maxStreak = max($maxStreak, $currentStreak);
                        $prevDate = $date;
                    }

                    return $maxStreak;

                case 'recharge_peak_amount': // 最高儲值金額
                    return UserPayOrders::where('uid', $uid)
                        ->where('currency', 'TWD')
                        ->where('status', 'success')
                        ->max('amount');

                case 'recharge_recent_amount': // 最近一次儲值金額
                    return UserPayOrders::where('uid', $uid)
                        ->where('currency', 'TWD')
                        ->where('status', 'success')
                        ->orderBy('created_at', 'desc')
                        ->first()
                        ->amount;

                case 'recharge_total_amount': // 累計儲值金額
                    return UserPayOrders::where('uid', $uid)
                        ->where('currency', 'TWD')
                        ->where('status', 'success')
                        ->sum('amount');

                case 'gacha_draw_times': // 轉蛋次數 (待確認)
                    return 0;
                case 'gacha_draw_total': // 累計轉蛋次數
                    return UserGachaOrders::where('uid', $uid)->sum('times');
                case 'mission_spend_mall_coin': // 任務花費商城幣 (待確認)
                    return 0;
                case 'spend_mall_coin_total': // 累計花費商城幣 (100)
                    return UserItemLogs::where('user_id', $user->id)
                        ->where('item_id', '100')
                        ->where('qty', '<', 0)
                        ->sum('qty');
                case 'mission_spend_game_coin': // 任務花費遊戲幣 (待確認)
                    return 0;
                case 'spend_game_coin_total': // 累計花費遊戲幣 (100)
                    return UserItemLogs::where('user_id', $user->id)
                        ->where('item_id', '101')
                        ->where('qty', '<', 0)
                        ->sum('qty');
                case 'map_edit_times': // 地圖編輯次數 (待確認)
                    return 0;
                case 'map_edit_total': // 累計地圖編輯次數
                    $recordCount = UserStats::where('uid', $uid)->first()->map_edit_total;

                    return $recordCount + 1;
                case 'map_publish_total': // 累計地圖發布次數
                    $recordCount = UserMaps::where('user_id', $user->id)->where('is_publish', 1)->count();

                    return $recordCount;
                case 'ugc_play_times': // 遊戲內玩家關卡遊玩次數
                    return 1;
                case 'ugc_play_total': // 累計遊戲內玩家關卡遊玩次數
                    $originRecordCount = UserStats::where('uid', $uid)->first()->ugc_play_total;

                    return $originRecordCount + 1;
                case 'ugc_clear_times': // 遊戲內玩家關卡清除次數
                    $recordCount = UserStats::where('uid', $uid)->first()->ugc_clear_times;

                    return $recordCount + 1;
                case 'ugc_clear_total': // 累計遊戲內玩家關卡清除次數
                    $recordCount = UserStats::where('uid', $uid)->first()->ugc_clear_total;

                    return $recordCount + 1;
                case 'mission_visit_home': // 任務期間訪問好友家次數 (待確認)
                    return 0;
                case 'visit_home_total': // 累計訪問好友家次數
                    $recordCount = UserStats::where('uid', $uid)->first()->visit_home_total;

                    return $recordCount + 1;
                case 'spend_stamina_total': // 累計花費體力
                    return UserStaminaLog::where('uid', $uid)
                        ->where('change_stamina', '<', 0)
                        ->sum('change_stamina') * -1;

                case 'sr_accessory_obtained_count': // 累計SR道具取得次數
                case 'sr_accessory_owned_count':    // 擁有SR道具最高值
                    return UserItems::whereHas('item', function ($query) {
                        $query->where('category', 'AvatarItem')
                            ->where('rarity', 'SR');
                    })
                        ->where('user_id', $user->id)
                        ->where('qty', '>', 0)
                        ->sum('qty');
                case 'ssr_accessory_obtained_count': // 累計SSR道具取得次數
                case 'ssr_accessory_owned_count':    // 擁有SSR道具最高值
                    return UserItems::whereHas('item', function ($query) {
                        $query->where('category', 'AvatarItem')
                            ->where('rarity', 'SSR');
                    })
                        ->where('user_id', $user->id)
                        ->where('qty', '>', 0)
                        ->sum('qty');
                case 'doll_accessory_obtained_total': // 紙娃娃配件取得次數
                    return UserItems::whereHas('item', function ($query) {
                        $query->where('category', 'AvatarItem');
                    })
                        ->where('user_id', $user->id)
                        ->where('qty', '>', 0)
                        ->sum('qty');
                case 'furniture_type_obtained_total': // 取得家具類型總數
                case 'furniture_type_owned_total':    // 取得家具類型總數
                    return UserItems::whereHas('item', function ($query) {
                        $query->where('category', 'Furniture');
                    })
                        ->where('user_id', $user->id)
                        ->where('qty', '>', 0)
                        ->sum('qty');
                case 'minigame_play_total': // 累計遊戲內小遊戲遊玩次數
                    $recordCount = UserStats::where('uid', $uid)->first()->minigame_play_total;

                    return $recordCount + 1;
                case 'pet_level_total': // 寵物等級加總
                    $pets = UserPet::where('uid', $user->uid)
                        ->where('pet_level', '>', 0)
                        ->get();
                    $totalLevel = $pets->sum('pet_level');

                    return $totalLevel;
                case 'pet_1_level': // 寵物1等級
                    $pet1 = UserPet::where('uid', $user->uid)
                        ->where('pet_id', 1)
                        ->first();
                    if ($pet1) {
                        return $pet1->pet_level;
                    }

                    // 如果沒有寵物1，則返回0
                    return 0;
                case 'pet_2_level': // 寵物2等級
                    $pet2 = UserPet::where('uid', $user->uid)
                        ->where('pet_id', 2)
                        ->first();
                    if ($pet2) {
                        return $pet2->pet_level;
                    }

                    return 0;
                case 'pet_3_level': // 寵物3等級
                    $pet3 = UserPet::where('uid', $user->uid)
                        ->where('pet_id', 3)
                        ->first();
                    if ($pet3) {
                        return $pet3->pet_level;
                    }

                    return 0;
                case 'pet_4_level': // 寵物4等級
                    $pet4 = UserPet::where('uid', $user->uid)
                        ->where('pet_id', 4)
                        ->first();
                    if ($pet4) {
                        return $pet4->pet_level;
                    }

                    return 0;
                case 'pet_5_level': // 寵物5等級
                    $pet5 = UserPet::where('uid', $user->uid)
                        ->where('pet_id', 5)
                        ->first();
                    if ($pet5) {
                        return $pet5->pet_level;
                    }

                    // 如果沒有寵物5，則返回0
                    return 0;
                case 'pet_6_level': // 寵物6等級
                    $pet6 = UserPet::where('uid', $user->uid)
                        ->where('pet_id', 6)
                        ->first();
                    if ($pet6) {
                        return $pet6->pet_level;
                    }

                    return 0;
                case 'summon_count1':           // 一次叫出 1 隻以上寵物的次數
                case 'summon_count2':           // 累計一次叫出 2 隻以上寵物的次數
                case 'summon_count3':           // 累計一次叫出 3 隻以上寵物的次數
                case 'summon_times_pig':        // 召喚過幾隻寵物 1（一次3隻就+3）
                case 'summon_times_chameleon':  // 召喚過幾隻寵物 2（一次3隻就+3）
                case 'summon_times_cow':        // 召喚過幾隻寵物 3（一次3隻就+3）
                case 'summon_times_rabbit':     // 召喚過幾隻寵物 4（一次3隻就+3）
                case 'summon_times_pufferfish': // 召喚過幾隻寵物 5（一次3隻就+3）
                case 'summon_times_bear':       // 召喚過幾隻寵物 6（一次3隻就+3）
                case 'summon_count3_samepet':   // 召喚同隻寵物 3 次以上
                    $recordCount = UserStats::where('uid', $uid)->first()->$column;

                    return $recordCount + $value;
                case 'max_followers_count': // 生涯最高被追蹤
                    return Follows::withTrashed()
                        ->where('following_uid', $uid)
                        ->distinct('follower_uid')
                        ->count('follower_uid');

                case 'max_map_like_count': // 最大地圖按讚數
                    return UserMaps::where('user_id', $user->id)
                        ->max('like_count');
                case 'mission_watch_ads_count': // 任務期間觀看廣告次數 (待確認)
                    return 0;

                default:
                    return null;
            }
        } catch (\Exception $e) {
            Log::error('計算玩家統計資料失敗: ', ['uid' => $uid, 'column' => $column, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** 更新統計資料 */
    private function updateStatColumn(string $uid, string $column, int $value): void
    {
        try {
            // 取出該玩家最新一筆
            $userStats = UserStats::where('uid', $uid)
                ->orderByDesc('id')
                ->first();

            // 若不存在則建立一筆
            if (! $userStats) {
                $userStats = UserStats::create([
                    'uid' => $uid,
                    $column => $value,
                ]);

                return;
            }

            // 只有值不同時才更新
            if ($value !== $userStats->$column) {
                $userStats->$column = $value;
                $userStats->save();
            }

        } catch (\Throwable $e) {
            Log::error('更新玩家統計資料失敗', [
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
        }
    }



    /**
     * 取得符合條件的任務ID（daily、weekly 對應當天與本週）
     */
    private function getTaskIdsByColumn(string $column, int $uid): array
    {
        $todayStart = Carbon::today();
        $todayEnd = Carbon::tomorrow();
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();

        $tasks = UserTasks::where('user_tasks.uid', $uid)
            ->leftJoin('tasks', 'user_tasks.task_id', '=', 'tasks.id')
            ->where('tasks.type', '!=', 'grade')
            ->where(function ($query) use ($todayStart, $todayEnd, $weekStart, $weekEnd) {
                $query
                    // daily 任務：只取今天建立的
                    ->orWhere(function ($q) use ($todayStart, $todayEnd) {
                        $q->where('tasks.type', 'daily')
                            ->whereBetween('user_tasks.created_at', [$todayStart, $todayEnd]);
                    })
                    // weekly 任務：只取本週建立的
                    ->orWhere(function ($q) use ($weekStart, $weekEnd) {
                        $q->where('tasks.type', 'weekly')
                            ->whereBetween('user_tasks.created_at', [$weekStart, $weekEnd]);
                    })
                    // 其他任務類型：不限制時間
                    ->orWhereNotIn('tasks.type', ['daily', 'weekly']);
            })
            ->get([
                'user_tasks.id',
                'tasks.condition',
                'tasks.type',
            ]);

        // 篩選出符合 action 的
        return $tasks->filter(function ($task) use ($column) {
            $condition = is_string($task->condition)
                ? json_decode($task->condition, true)
                : $task->condition;

            return is_array($condition)
                && isset($condition['action'])
                && $condition['action'] === $column;
        })
            ->pluck('id')
            ->toArray();
    }

    /** 關鍵字 */
    public function keywords(): array
    {
        return [
            'login' => [
                'login_total_days',
                'login_streak_days',
                'login_streak_max',
            ],
            'recharge' => [
                'recharge_peak_amount',
                'recharge_recent_amount',
                'recharge_total_amount',
            ],
            'gacha' => [
                'gacha_draw_times',
                'gacha_draw_total',
            ],
            'mall_coin' => [
                'mission_spend_mall_coin',
                'spend_mall_coin_total',
            ],
            'game_coin' => [
                'mission_spend_game_coin',
                'spend_game_coin_total',
            ],
            'map' => [
                'map_edit_times',
                'map_edit_total',
                'map_publish_total',
            ],
            'ugc_play' => [
                'ugc_play_times',
                'ugc_play_total',
            ],
            'ugc_clear' => [
                'ugc_clear_times',
                'ugc_clear_total',
            ],
            'stamina' => [
                'spend_stamina_total',
            ],
            'visit' => [
                'mission_visit_home',
                'visit_home_total',
            ],
            'avatar_sr' => [
                'sr_accessory_obtained_count',
                'sr_accessory_owned_count',
            ],
            'avatar_ssr' => [
                'ssr_accessory_obtained_count',
                'ssr_accessory_owned_count',
            ],
            'avatar_all' => [
                'doll_accessory_obtained_total',
            ],
            'furniture' => [
                'furniture_type_obtained_total',
                'furniture_type_owned_total',
            ],
            'mini_game' => [
                'minigame_play_total',
            ],
            'pet_level' => [
                'pet_level_total',
                'pet_1_level',
                'pet_2_level',
                'pet_3_level',
                'pet_4_level',
                'pet_5_level',
                'pet_6_level',
            ],
            'summon' => [
                'summon_count1',
                'summon_count2',
                'summon_count3',
                'summon_times_pig',
                'summon_times_chameleon',
                'summon_times_cow',
                'summon_times_rabbit',
                'summon_times_pufferfish',
                'summon_times_bear',
                'summon_count3_samepet',
            ],
            'follow' => [
                'max_followers_count',
            ],
            'map_like' => [
                'max_map_like_count',
            ],
            'ads' => [
                'mission_watch_ads_count',
            ],
        ];
    }

    public function formatterHasFinishedTaskResult($completedTasks)
    {
        $formattedTaskResult = [];
        foreach ($completedTasks as $task) {
            $formattedTaskResult[] = [
                'task_id' => $task->id,
                'task_name' => $task->name,
            ];
        }
    }

    /** 取得欄位關鍵字 */
    public function getKeywordByColumn(string $column): ?string
    {
        foreach ($this->keywords() as $keyword => $columns) {
            if (in_array($column, $columns)) {
                return $keyword;
            }
        }

        return null;
    }
}
