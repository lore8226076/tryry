<?php

namespace App\Service;

use App\Models\MiniGameRanks;
use App\Models\TaskCategory;
use App\Models\Tasks;
use App\Models\UserGachaOrders;
use App\Models\UserItemLogs;
use App\Models\UserItems;
use App\Models\UserLoginLogs;
use App\Models\Users;
use App\Models\UserStaminaLog;
use App\Models\UserTasks;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TaskService
{
    /** 取得啟用中的任務類型 */
    public function getActiveTaskCategories($type = null)
    {
        $query = TaskCategory::where('is_active', true);
        if ($type !== null) {
            if ($type === 'special-tasks') {
                $query->whereIn('id', [4, 5]);
            } else {
                $query->where('show_type', 'like', '%'.$type.'%');
            }
        } else {
            // 排除 events 和 special
            $query->whereNotIn('id', [4, 5]);
        }

        return $query->get()->map(function ($category) {
            $category->show_type = $category->show_type;

            return $category;
        });
    }

    /** 取得所有啟用中的任務 */
    public function getAvailableTasks(string $uid, ?string $type = null)
    {
        // 組合快取 Key
        $cacheKey = 'tasks:list:'.($type ?? 'all');

        $tasks = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($type) {
            $query = Tasks::whereHas('category', function ($q) {
                $q->where('is_active', true);
            })
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('start_at')
                        ->orWhere('start_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('end_at')
                        ->orWhere('end_at', '>=', now());
                });

            if ($type) {
                $query->where('type', $type);
            }

            $result = $query->get();
            // 格式化任務獎勵
            foreach ($result as $task) {
                $task->reward = $this->formatTaskRewards($task->reward);
            }

            return $result;
        });

        return $tasks;
    }

    /** 取得玩家當前任務列表 */
    public function getCurrentTasks(string $uid, $status = null)
    {
        // 未完成 或是 已完成但有獎勵沒有拿
        $tasks = UserTasks::whereHas('task', function ($q) {
            $q->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('start_at')
                        ->orWhere('start_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('end_at')
                        ->orWhere('end_at', '>=', now());
                });
        })
            ->where('uid', $uid)
            ->where(function ($query) use ($status) {
                if ($status !== null) {
                    $query->where('status', $status);
                } else {
                    $query->where('status', 'in_progress')
                        ->orWhere(function ($query) {
                            $query->where('status', 'completed');
                        });
                }
            })
        // 加上過濾每日、每週任務日期條件
            ->where(function ($query) {
                $query->whereHas('task', function ($q) {
                    $q->whereNotIn('repeatable_type', [1, 2]);
                })
                    ->orWhere(function ($q) {
                        // 每日任務只顯示今天
                        $q->whereHas('task', function ($task) {
                            $task->where('repeatable_type', 1);
                        })->whereDate('created_at', now()->toDateString());
                    })
                    ->orWhere(function ($q) {
                        // 每週任務只顯示本週
                        $q->whereHas('task', function ($task) {
                            $task->where('repeatable_type', 2);
                        })->where('created_at', '>=', now()->startOfWeek());
                    });
            })
        // 排序優先度 已完成且領獎 > 已完成未領獎 > 進行中 > 其他
            ->orderByRaw("
            CASE
            WHEN status = 'completed' AND reward_status = 0 THEN 1
            WHEN status = 'in_progress' THEN 2
            WHEN status = 'completed' AND reward_status = 1 THEN 3
            ELSE 4
            END
            ")
            ->orderBy('id')
            ->get();

        return $tasks;
    }

    /** 接取任務 */
    public function assignTaskToUser(string $uid, Tasks $task)
    {
        $condition = $task->condition;

        $progress = [];
        foreach ($condition as $key => $value) {
            if ($key === 'action') {
                continue;
            }
            $progress[$key] = 0;
        }
        if ($this->checkDuplicateTask($uid, $task->id)) {
            \Log::error("使用者 {$uid} 嘗試接取任務 {$task->id} 但已在1分鐘內重複接取。");

            return false; // 如果已經有同一天的任務，就不再接取
        }

        try {
            DB::transaction(function () use ($uid, $task) {
                if (! $this->checkDuplicateTask($uid, $task->id)) {
                    UserTasks::create([
                        'uid' => $uid,
                        'task_id' => $task->id,
                        'status' => 'in_progress',
                        'progress' => [],
                        'reward_status' => 0,
                        'completed_at' => null,
                    ]);
                }
            });
        } catch (\Illuminate\Database\QueryException $e) {
            \Log::warning("重複任務接取(唯一索引錯誤): uid={$uid}, task_id={$task->id}, error={$e->getMessage()}");

            return false;
        }

    }

    /** 提交進度 */
    public function submitProgress(string $uid, int $taskId, array $progress)
    {
        $userTask = UserTasks::where('uid', $uid)->where('id', $taskId)->latest()->first();
        $task = Tasks::find($userTask->task_id);

        // 型別防呆
        if (is_array($progress) && isset($progress['count'])) {
            $progress['count'] = (int) $progress['count'];
        }
        $userTask->progress = $progress;
        $userTask->save();
        if ($this->checkIfTaskCompleted($task, $progress)) {
            $userTask->status = 'completed';
            $userTask->completed_at = now();
            $userTask->save();
        }

        return $userTask;
    }

    /** 領獎 */
    public function changeRewardStatus(string $uid, int $id)
    {
        $userTask = UserTasks::where('uid', $uid)->where('id', $id)->latest()->first();
        if (empty($userTask)) {
            return false;
        }
        $userTask->reward_status = true; // 領獎狀態
        $userTask->save();

        return true;
    }

    // 取消任務
    public function cancleTask(string $uid, int $taskId, int $id)
    {
        $userTask = UserTasks::where('uid', $uid)->where('task_id', $taskId)->where('id', $id)->firstOrFail();
        $userTask->status = 'cancelled';
        $userTask->save();
    }

    /** 檢查前置任務 */
    public function checkPreTask(Tasks $task, string $uid)
    {
        if ($task->prev_task_id) {
            $prevTask = UserTasks::where('uid', $uid)->where('task_id', $task->prev_task_id)->first();
            if (! $prevTask || $prevTask->status !== 'completed') {
                return false;
            }
        }

        return true;
    }

    public function checkRepeatTask(Tasks $task, UserTasks $userTask, string $uid)
    {
        $repeatableType = $task->repeatable_type;
        $lastCreatedAt = $userTask->created_at;
        $now = now();

        switch ($repeatableType) {
            case -1: // 任務完成後可立即再接
                return $userTask->status === 'completed';

            case 0: // 任務完成後不可再接
                return false;

            case 1: // 每日重置
                return ! $lastCreatedAt->isSameDay($now);

            case 2: // 每週重置
                return $lastCreatedAt->startOfWeek() < $now->startOfWeek();

            case 3: // 每月重置
                return $lastCreatedAt->month !== $now->month || $lastCreatedAt->year !== $now->year;

            default:
                return false;
        }
    }

    /** 自動接取任務 */
    public function autoAssignTasks(string $uid, array $taskIds = [])
    {
        $tasks = Tasks::where('is_active', true)
            ->where('type', '!=', 'grade')
            ->where(function ($query) {
                $query->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_at')->orWhere('end_at', '>=', now());
            });

        if (! empty($taskIds)) {
            $tasks->whereIn('id', $taskIds);
        }

        $tasks = $tasks->get();
        $alreadyClearDailyPoint = false;
        $alreadyClearWeeklyPoint = false;
        foreach ($tasks as $task) {
            $needAssign = false;
            switch ($task->repeatable_type) {
                case 1: // 每日任務只判斷今天是否已接
                    $needAssign = ! UserTasks::where('uid', $uid)
                        ->where('task_id', $task->id)
                        ->whereDate('created_at', now()->toDateString())
                        ->exists();

                    break;
                case 2: // 每週任務判斷本週是否已接
                    $needAssign = ! UserTasks::where('uid', $uid)
                        ->where('task_id', $task->id)
                        ->where('created_at', '>=', now()->startOfWeek())
                        ->exists();
                    break;
                case 3: // 每月任務判斷本月是否已接
                    $needAssign = ! UserTasks::where('uid', $uid)
                        ->where('task_id', $task->id)
                        ->whereYear('created_at', now()->year)
                        ->whereMonth('created_at', now()->month)
                        ->exists();
                    break;
                case -1: // 解完就能立刻再接
                    $needAssign = UserTasks::where('uid', $uid)
                        ->where('task_id', $task->id)
                        ->latest()->first()->status === 'completed';
                    break;
                case 0: // 完成後不能再接
                    $needAssign = ! UserTasks::where('uid', $uid)
                        ->where('task_id', $task->id)
                        ->exists();
                    break;
                default:
                    $needAssign = false;
                    break;
            }

            if ($needAssign) {
                // 如果任務為每日或每周, 要先確保清空道具數量
                if ($task->repeatable_type === 1 && ! $alreadyClearDailyPoint) {
                    $this->clearTaskItems($uid, $task->id);
                    $alreadyClearDailyPoint = true;
                } elseif ($task->repeatable_type === 2 && ! $alreadyClearWeeklyPoint) {
                    $this->clearTaskItems($uid, $task->id);
                    $alreadyClearWeeklyPoint = true;
                }
                $this->assignTaskToUser($uid, $task);
            }
        }

        return true;
    }

    /** 取得玩家任務 */
    public function getUserTask(string $uid, int $taskId, ?int $id = null)
    {
        $query = UserTasks::where('uid', $uid)->where('task_id', $taskId);
        if ($id !== null) {
            $query->where('id', $id);
        }

        return $query->latest()->first();
    }

    /** 取得玩家已完成但未領獎的任務 */
    public function getCompletedTasks(string $uid, array $taskIds = [])
    {

        $query = UserTasks::with('task')
            ->where('uid', $uid)
            ->where('status', 'completed')
            ->where('reward_status', false)
            ->whereHas('task', function ($q) {
                $q->where('type', '!=', 'grade')
                    ->where('is_active', true);
            });

        // === 加入 daily / weekly 時間條件 ===
        $todayStart = Carbon::today();
        $todayEnd = Carbon::tomorrow();
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();

        $query->where(function ($q) use ($todayStart, $todayEnd, $weekStart, $weekEnd) {
            $q
                ->orWhere(function ($q2) use ($todayStart, $todayEnd) {
                    $q2->whereHas('task', fn ($t) => $t->where('type', 'daily'))
                        ->whereBetween('created_at', [$todayStart, $todayEnd]);
                })
                ->orWhere(function ($q2) use ($weekStart, $weekEnd) {
                    $q2->whereHas('task', fn ($t) => $t->where('type', 'weekly'))
                        ->whereBetween('created_at', [$weekStart, $weekEnd]);
                })
                ->orWhereHas('task', fn ($t) => $t->whereNotIn('type', ['daily', 'weekly']));
        });

        if (! empty($taskIds)) {
            $query->whereIn('task_id', $taskIds);
        }

        // === 確保相同 task_id 只取 id 最大的一筆 ===
        // 這裡利用子查詢先選出每個 task_id 的最大 id
        $latestTaskIds = UserTasks::select(DB::raw('MAX(id) as id'))
            ->where('uid', $uid)
            ->groupBy('task_id')
            ->pluck('id')
            ->toArray();

        $query->whereIn('id', $latestTaskIds);

        return $query->get();
    }

    /** 檢查任務是否已完成 */
    private function checkIfTaskCompleted(Tasks $task, array $progress): bool
    {
        $condition = $task->condition;

        foreach ($condition as $key => $value) {
            if ($key === 'action') {
                continue;
            }

            if (intval($value) <= intval($progress[$key])) {
                return true;
            }
        }

        return false;
    }

    /** 任務condition action 關鍵字 */
    public function keywords(): array
    {
        return [
            // 任務
            'login' => ['login', 'weekly_login', 'login_event'],
            'gacha' => ['gacha', 'weekly_gacha'],
            'mini_game' => ['mini_game'],
            'reward' => ['daily_bonus', 'weekly_bonus'],
            'newbie' => [
                'teaching_task',
                'teaching_pet',
                'teaching_levelselector',
                'teaching_maplobby',
                'teaching_mapeditor',
                'teaching_gacha',
                'teaching_finished',
            ],
            'map' => [
                'build',
                'weekly_build',
            ],
            'visit' => [
                'visit',
                'weekly_visit',
            ],

            // 統計關聯的任務
            'ugc_play' => ['play', 'weekly_play'],
            'ugc_clear' => ['clear', 'weekly_clear'],
            'visit' => ['visit', 'weekly_visit'],
            'mall_coin' => ['purchase'],
            'map' => ['build', 'weekly_build'],
            'stamina' => ['stamina', 'weekly_stamina'],
        ];
    }

    /** 計算統計資料  */
    public function calculateStat(string $uid, string $column, $value = null): ?int
    {
        $secondsUntilEndOfDay = now()->endOfDay()->diffInSeconds(now());
        $user = Users::select('id', 'uid')->where('uid', $uid)->first();
        switch ($column) {
            // 活動登入
            case 'login_event':
                $period = $this->getEventDate('login_event');
                if (! $period) {
                    return 0;
                }

                // timestamp to datetime
                $startAt = date('Y-m-d H:i:s', $period['start_at']);
                $endAt = date('Y-m-d H:i:s', $period['end_at']);

                $query = UserLoginLogs::where('uid', $uid)
                    ->where('created_at', '>=', $startAt);

                if (! empty($period['end_at'])) {
                    $query->where('created_at', '<=', $endAt);
                }

                return $query
                    ->selectRaw('COUNT(DISTINCT DATE(created_at)) as days')
                    ->value('days');
                break;
                // 今天是否有登入過
            case 'login':
                return UserLoginLogs::where('uid', $uid)
                    ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
                    ->exists() ? 1 : 0;
                break;

                // 今天是否有進行扭蛋
            case 'gacha':
                return UserGachaOrders::where('uid', $uid)
                    ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
                    ->exists() ? 1 : 0;
                break;

                // 今天用商城幣消費多少
            case 'purchase':
                $todayStart = now()->startOfDay();
                $todayEnd = now()->endOfDay();
                // 如果商城沒花錢，查轉蛋記錄
                $total = UserItemLogs::where('user_id', $user->id)
                    ->where('item_id', 100)
                    ->where('qty', '<', 0)
                    ->whereBetween('created_at', [$todayStart, $todayEnd])
                    ->sum('qty');

                $total = abs($total);

                return $total;
                break;
                // 檢查當前201積分 日任務
            case 'daily_bonus':
                return UserItems::where('uid', $uid)
                    ->where('item_id', 201)
                    ->first()?->qty ?? 0;
                break;
                // 檢查當前登入幾天
            case 'weekly_login':
                return UserLoginLogs::where('uid', $uid)
                    ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                    ->selectRaw('COUNT(DISTINCT DATE(created_at)) as days')
                    ->value('days');
                break;
                // 檢查當前扭蛋幾次
            case 'weekly_gacha':
                return UserGachaOrders::where('uid', $uid)
                    ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                    ->sum('times');
                // 檢查當前202積分 周任務
            case 'weekly_bonus':
                return UserItems::where('uid', $uid)
                    ->where('item_id', 202)
                    ->first()?->qty ?? 0;
                break;
                // 至少玩一次小遊戲
            case 'mini_game':
                return MiniGameRanks::where('user_id', $user->id)
                    ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                    ->count();
                break;
            case 'teaching_square': // 是否通關廣場教學
                return Users::where('uid', $uid)
                    ->where('teaching_square', 1)
                    ->exists() ? 1 : 0;
                break;
            case 'weekly_clear': // 遊戲內玩家關卡通關次數
                $taskId = 45;
                $userTask = $this->getLatestTask($uid, $taskId);
                $value = isset($userTask->progress['count']) ? $userTask->progress['count'] + 1 : 1;

                return $value;
            case 'clear': // 遊戲內玩家關卡通關次數
                return 1;
            case 'build':
                $taskId = 6;
                $userTask = $this->getLatestTask($uid, $taskId);
                $value = isset($userTask->progress['count']) ? $userTask->progress['count'] + 1 : 1;

                return $value;
            case 'weekly_build':
                $taskId = 48;
                $userTask = $this->getLatestTask($uid, $taskId);
                $value = isset($userTask->progress['count']) ? $userTask->progress['count'] + 1 : 1;

                return $value;
            case 'weekly_play': // 遊戲內玩家關卡遊玩次數
                $taskId = 43;
                $userTask = $this->getLatestTask($uid, $taskId);
                $value = isset($userTask->progress['count']) ? $userTask->progress['count'] + 1 : 1;

                return $value;
                break;
            case 'play': // 遊戲內玩家關卡遊玩次數
                $taskId = 3;
                $userTask = $this->getLatestTask($uid, $taskId);
                $value = isset($userTask->progress['count']) ? $userTask->progress['count'] + 1 : 1;

                return $value;
                break;
            case 'visit':
                $taskId = 9;
                $userTask = $this->getLatestTask($uid, $taskId);
                $value = isset($userTask->progress['count']) ? $userTask->progress['count'] + 1 : 1;

                return $value;
                break;
            case 'weekly_visit':
                $taskId = 50;
                $userTask = $this->getLatestTask($uid, $taskId);
                $value = isset($userTask->progress['count']) ? $userTask->progress['count'] + 1 : 1;

                return $value;
                break;
                // 當日體力消耗
            case 'stamina':
                $datas = UserStaminaLog::where('uid', $uid)
                    ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
                    ->where('change_stamina', '<', 0)
                    ->get();

                $total = $datas->sum(function ($data) {
                    return $data->change_stamina;
                });

                // 因為是負數所以要取絕對值
                return abs($total);
                break;
            case 'weekly_stamina':
                $datas = UserStaminaLog::where('uid', $uid)
                    ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                    ->where('change_stamina', '<', 0)
                    ->get();

                $total = $datas->sum(function ($data) {
                    return $data->change_stamina;
                });

                return abs($total);
            case 'teaching_task':
                return Users::where('uid', $uid)
                    ->where('teaching_task', 1)
                    ->exists() ? 1 : 0;
            case 'teaching_pet':
                return Users::where('uid', $uid)
                    ->where('teaching_pet', 1)
                    ->exists() ? 1 : 0;
            case 'teaching_levelselector':
                return Users::where('uid', $uid)
                    ->where('teaching_levelselector', 1)
                    ->exists() ? 1 : 0;
            case 'teaching_maplobby':
                return Users::where('uid', $uid)
                    ->where('teaching_maplobby', 1)
                    ->exists() ? 1 : 0;
            case 'teaching_mapeditor':
                return Users::where('uid', $uid)
                    ->where('teaching_mapeditor', 1)
                    ->exists() ? 1 : 0;
            case 'teaching_gacha':
                return 1;
            case 'teaching_finished':
                return Users::where('uid', $uid)
                    ->where('teaching_task', 1)
                    ->where('teaching_pet', 1)
                    ->where('teaching_levelselector', 1)
                    ->where('teaching_maplobby', 1)
                    ->where('teaching_mapeditor', 1)
                    ->exists() ? 1 : 0;
                break;
        }

        return null;
    }

    /** 取得活動日期 */
    public function getEventDate(string $action): ?array
    {
        $task = Tasks::where('start_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('end_at')->orWhere('end_at', '>=', now());
            })
            ->get()
            ->filter(function ($task) use ($action) {
                return isset($task->condition['action']) && $task->condition['action'] === $action;
            })
            ->sortByDesc('start_at')
            ->first();
        if (! $task || ! $task->start_at) {
            return null;
        }

        return [
            'start_at' => $task->start_at,
            'end_at' => $task->end_at ?? null,
        ];
    }

    /** 取得相關活動 */
    public function getRelatedActivity(string $action)
    {
        $now = now();

        return Tasks::select('id', 'condition', 'repeatable_type')->
            where(function ($query) use ($now) {
                $query->whereNull('start_at')
                    ->orWhere('start_at', '<=', $now);
            })
                ->where(function ($query) use ($now) {
                    $query->whereNull('end_at')
                        ->orWhere('end_at', '>=', $now);
                })
                ->get()
                ->filter(function ($task) use ($action) {
                    return isset($task->condition['action']) && $task->condition['action'] === $action;
                });
    }

    public function formatCompletedTasks($completedTasks)
    {
        // 過濾出 type 缺少或任務不存在的項目，並記錄 log
        $completedTasks->each(function ($task) {
            if (empty($task->task?->type)) {
                \Log::error('沒有Type的任務', ['task' => $task]);
            }
        });

        // newbie
        $finishedNewbieTask = $completedTasks->filter(function ($task) {
            return $task->task?->type === 'newbie';
        });

        //  event_7days
        $finishedEvent7daysTask = $completedTasks->filter(function ($task) {
            return $task->task?->type === 'event_7days';
        });

        // 其他
        $finishedNormalTask = $completedTasks->filter(function ($task) {
            return ! in_array($task->task?->type, ['newbie', 'event_7days', 'grade', 'Event_7Days']);
        });

        return [
            'quest' => [
                'hasFinishedTasks' => $finishedNormalTask->isNotEmpty(),
            ],
            'newbie' => [
                'hasFinishedTasks' => $finishedNewbieTask->isNotEmpty(),
            ],
            'event_7days' => [
                'hasFinishedTasks' => $finishedEvent7daysTask->isNotEmpty(),
            ],
        ];
    }

    private function checkDuplicateTask(string $uid, int $taskId): bool
    {
        $now = now();
        $tenMinutesAgo = $now->copy()->subMinutes(1);

        return UserTasks::where('uid', $uid)
            ->where('task_id', $taskId)
            ->whereBetween('created_at', [$tenMinutesAgo, $now])
            ->exists();
    }

    private function getLatestTask(string $uid, int $taskId)
    {
        return UserTasks::where('uid', $uid)
            ->where('task_id', $taskId)
            ->latest()
            ->first();
    }

    private function clearTaskItems(string $uid, int $taskId)
    {
        $task = Tasks::find($taskId);
        if ($task->repeatable_type === 1) {
            // 檢查數量是否>1 如果>1 則清空
            $userItems = UserItems::where('uid', $uid)
                ->where('item_id', 201)
                ->first();

            if ($userItems->qty > 1) {
                try {
                    $userItems->qty = 0;
                    $userItems->save();
                } catch (\Exception $e) {
                    \Log::error('任務系統清空每日任務道具失敗', ['error' => $e->getMessage(), 'taskId' => $taskId, 'uid' => $uid, 'current_date' => now()->format('Y-m-d')]);
                }
            }
        }
        if ($task->repeatable_type === 2) {
            $userItems = UserItems::where('uid', $uid)
                ->where('item_id', 202)
                ->first();

            if ($userItems->qty > 1) {
                try {
                    $userItems->qty = 0;
                    $userItems->save();
                } catch (\Exception $e) {
                    \Log::error('任務系統清空每周任務道具失敗', ['error' => $e->getMessage(), 'taskId' => $taskId, 'uid' => $uid, 'current_date' => now()->format('Y-m-d')]);
                }
            }
        }
    }

    public function formatTaskRewards($rewards)
    {
        $formatted = [];

        // 若 reward 已經是陣列 (ex: [100,1])
        if (is_array($rewards)) {
            if (count($rewards) >= 2) {
                $formatted[] = [
                    'item_id' => (int) $rewards[0],
                    'amount' => (int) $rewards[1],
                ];
            }

            return $formatted;
        }

        // 若 reward 是字串格式 (ex: "[100,1],[101,2]")
        if (is_string($rewards)) {
            preg_match_all('/\[(\d+)\s*,\s*(\d+)\]/', $rewards, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $formatted[] = [
                    'item_id' => (int) $match[1],
                    'amount' => (int) $match[2],
                ];
            }
        }

        return $formatted;
    }
}
