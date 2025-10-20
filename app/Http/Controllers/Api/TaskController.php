<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tasks;
use App\Models\UserItemLogs;
use App\Models\Users;
use App\Models\UserTasks;
use App\Service\ErrorService;
use App\Service\StaminaService;
use App\Service\TaskService;
use App\Service\UserItemService;
use App\Service\UserStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    protected $taskService;

    public function __construct(Request $request, TaskService $taskService)
    {
        $this->taskService = $taskService;

        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => ['list', 'currentList']]);
        }
    }

    public function list()
    {
        $user = auth()->guard('api')->user();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        $type = request()->input('type');
        $result = $this->taskService->getAvailableTasks($user->uid, $type);

        return response()->json(['data' => $result]);
    }

    /** 取得所有啟用中的任務類型 */
    public function categoryList($type = null)
    {
        $type = request()->input('type');
        if ($type !== null && $type !== 'events' && $type !== 'special' && $type !== 'special-tasks') {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0009'), 422);
        }

        $result = $this->taskService->getActiveTaskCategories($type);

        return response()->json(['data' => $result]);
    }

    /** 取得玩家當前任務列表 */
    public function currentList()
    {
        $status = request()->input('status');
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        // 自動接取任務
        $autoAssign = $this->taskService->autoAssignTasks($uid);

        // 取得當前任務列表
        $result = $this->taskService->getCurrentTasks($uid, $status);

        return response()->json(['data' => $result]);
    }

    /** 接取任務 */
    public function assign(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $taskId = $request->input('task_id');
        if (empty($taskId) || ! is_numeric($taskId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }

        $task = Tasks::find($taskId);
        if ($task == null) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }

        // 檢查任務時間
        if ($task->start_at !== null && $task->end_at !== null) {
            $now = time();
            if ($now < $task->start_at || $now > $task->end_at) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0002'), 422);
            }
        }

        // 檢查前置任務
        if (! $this->taskService->checkPreTask($task, $uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0006'), 422);
        }

        $userTask = $this->taskService->assignTaskToUser($uid, $task);
        if (! $userTask) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0003'), 422);
        }

        try {
            return response()->json(['message' => '任務領取成功', 'data' => $userTask]);
        } catch (\Exception $e) {
            \Log::error('任務領取失敗', [
                'message' => $e->getMessage(),
                'data' => $userTask,
            ]);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /** 提交進度 */
    public function progress(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $taskId = $request->input('task_id');
        if (empty($taskId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }
        $id = $request->input('progress_id');
        // 檢查是否有接任務
        $userTask = $this->taskService->getUserTask($uid, $taskId, $id);
        if (! $userTask) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }

        $progress = $request->input('progress');
        try {
            $userTask = $this->taskService->submitProgress($uid, $taskId, $progress);
            // ============ 任務系統 ============
            // 任務Service
            // 本次登入是否有完成任務
            $completedTask = $this->taskService->getCompletedTasks($uid);
            $formattedTaskResult = $this->taskService->formatCompletedTasks($completedTask);
            // ============ 任務系統 ============

            return response()->json(['message' => '進度已更新', 'data' => $userTask, 'finishedTask' => $formattedTaskResult]);
        } catch (\Exception $e) {

            \Log::error('任務進度更新失敗', [
                'message' => $e->getMessage(),
                'data' => $userTask,
            ]);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /** 領取獎勵 */
    public function reward(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $user = Users::where('uid', $uid)->first();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        $taskId = $request->input('task_id');
        if (empty($taskId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }
        $id = $request->input('progress_id');
        // 檢查任務是否已完成
        $userTask = $this->taskService->getUserTask($uid, $taskId, $id);
        if (! $userTask || $userTask->status !== 'completed') {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0003'), 422);
        }

        // 檢查任務是否已領取獎勵
        if ($userTask->reward_status) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0005'), 422);
        }

        try {
            $result = $this->taskService->changeRewardStatus($uid, $id);
            if ($result) {
                // 發放道具
                $reward = Tasks::find($taskId)->reward;
                $rewards = $this->parseReward($reward);

                $addFailed = false;
                $finalReward = [];
                foreach ($rewards as $item) {

                    $result = UserItemService::addItem(UserItemLogs::TYPE_SYSTEM, $user->id, $uid, $item['item_id'], $item['amount'], 1, '任務獎勵領取');
                    if ($result['success'] == 0) {
                        $addFailed = true;
                    } elseif ($result['success'] == 1 && isset($result['item_id'])) {
                        $finalReward[] = [
                            'item_id' => $result['item_id'],
                            'amount' => $result['qty'],
                        ];
                    } else {
                        $finalReward[] = $item;
                    }

                }
                $reward = $finalReward;
                if ($addFailed) {
                    return response()->json(ErrorService::errorCode(__METHOD__, 'UserItem:0002'), 422);
                }

                // 體力道具轉換成體力
                foreach ($reward as $item) {
                    if ($item['item_id'] == 200) {
                        $staminaResult = StaminaService::convertStamina($user->uid, $item['amount']);
                        if (empty($staminaResult['success'])) {
                            return response()->json(ErrorService::errorCode(__METHOD__, $staminaResult['error_code']), 422);
                        }
                    }
                }

                // ============ 任務系統 ============
                // 任務Service
                $taskService = new TaskService;
                // 玩家任務
                $taskStatsService = new UserStatsService($taskService, $taskService->keywords(), [$taskService, 'calculateStat']);
                $taskResult = $taskStatsService->updateByKeyword($user, 'reward');

                // 本次登入是否有完成任務
                $completedTask = $taskService->getCompletedTasks($user->uid);
                $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);
                // ============ 任務系統 ============

                return response()->json(['message' => '獎勵已發放', 'reward' => $reward, 'finishedTask' => $formattedTaskResult]);
            } else {
                return response()->json(ErrorService::errorCode(__METHOD__, 'UserItem:0002'), 422);
            }
        } catch (\Exception $e) {
            \Log::error('任務獎勵領取失敗', [
                'message' => $e->getMessage(),
                'data' => $userTask,
            ]);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /** 取消任務 */
    public function cancle(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $taskId = $request->input('task_id');
        if (empty($taskId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }
        $id = $request->input('progress_id');
        $userTask = $this->taskService->getUserTask($uid, $taskId, $id);
        if (! $userTask) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }
        $this->taskService->cancleTask($uid, $taskId, $id);

        return response()->json(['message' => '任務取消成功']);
    }

    /** 一鍵領取所有任務獎勵 */
    public function claimAllRewards()
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $user = Users::where('uid', $uid)->first();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        // 排除任務ID
        $excludeTaskIds = [50101, 50102, 50103, 50104, 50105, 50106, 50107, 50200, 50201, 50202, 50203, 50204, 50205, 50206];

        $userTasks = UserTasks::where('uid', $uid)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereNotIn('task_id', $excludeTaskIds)
            ->where('reward_status', 0)
            ->get();

        $rewardSummary = []; // 獎勵總和

        foreach ($userTasks as $userTask) {
            $result = $this->taskService->changeRewardStatus($uid, $userTask->id);
            if ($result) {
                $reward = Tasks::find($userTask->task_id)->reward;
                $rewards = $this->parseReward($reward);

                $addFailed = false;

                foreach ($rewards as $item) {
                    if (! isset($rewardSummary[$item['item_id']])) {
                        $rewardSummary[$item['item_id']] = 0;
                    }
                    $rewardSummary[$item['item_id']] += $item['amount'];

                    $result = UserItemService::addItem(
                        UserItemLogs::TYPE_SYSTEM,
                        $user->id,
                        $uid,
                        $item['item_id'],
                        $item['amount'],
                        1,
                        '任務獎勵領取'
                    );

                    if ($result['success'] == 0) {
                        $addFailed = true;
                        \Log::error('獎勵領取失敗', [
                            'message' => '獎勵領取失敗',
                            'data' => $item,
                            'user' => $user,
                            'uid' => $uid,
                            'userTask' => $userTask,
                        ]);
                    }

                    // 體力道具轉換成體力
                    if ($item['item_id'] == 200) {
                        $staminaResult = StaminaService::convertStamina($user->uid, $item['amount']);
                        if (empty($staminaResult['success'])) {
                            \Log::error('體力道具轉換失敗', [
                                'message' => '體力道具轉換失敗',
                                'data' => $item,
                                'user' => $user,
                                'uid' => $uid,
                                'userTask' => $userTask,
                            ]);
                        }
                    }

                }

                if ($addFailed) {
                    return response()->json(['message' => '獎勵領取失敗'], 422);
                }
            }
        }

        $rewardSummaryFormatted = collect($rewardSummary)->map(function ($amount, $itemId) {
            return [
                'item_id' => (int) $itemId,
                'amount' => $amount,
            ];
        })->values()->all();

        // ============ 任務系統 ============
        // 任務Service
        $taskService = new TaskService;
        // 玩家任務
        $taskStatsService = new UserStatsService($taskService, $taskService->keywords(), [$taskService, 'calculateStat']);
        $taskResult = $taskStatsService->updateByKeyword($user, 'reward');

        // 本次登入是否有完成任務
        $completedTask = $taskService->getCompletedTasks($user->uid);
        $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);
        // ============ 任務系統 ============

        return response()->json([
            'message' => '一鍵領取所有任務獎勵成功',
            'reward' => $rewardSummaryFormatted,
            'finishedTask' => $formattedTaskResult,
        ]);
    }

    /** 重置用戶任務 */
    public function reset(Request $request)
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

        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        // 強制清除任務
        UserTasks::where('uid', $uid)->forceDelete();
        Log::info("[重置任務] 使用者 {$uid} 任務已清除");

        try {
            Artisan::call('points:reset-all', [
                '--force' => true,
                '--uid' => $uid,
            ]);
        } catch (\Throwable $e) {
            Log::error('[重置任務] 執行 Artisan 指令失敗', [
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => '任務重置失敗'], 500);
        }

        return response()->json(['message' => '任務重置成功']);
    }

    /**
     * 將 reward 欄位轉換成統一格式：
     * [['item_id' => int, 'amount' => int], ...]
     */
    private function parseReward($rawReward): array
    {
        if (empty($rawReward)) {
            return [];
        }

        $reward = null;

        // Step 1: 嘗試直接 decode
        if (is_string($rawReward)) {
            $reward = json_decode($rawReward, true);

            // Step 2: 若 decode 失敗（例如不是完整 JSON）
            if (json_last_error() !== JSON_ERROR_NONE) {
                $rawReward = trim($rawReward);

                // 若沒有外層中括號，加上
                if (! Str::startsWith($rawReward, '[')) {
                    $rawReward = '['.$rawReward.']';
                }

                // 移除開頭亂碼或非 JSON 字元（例如 '9[100080,1]'）
                $rawReward = preg_replace('/^[^\[]+/', '', $rawReward);

                $reward = json_decode($rawReward, true);
            }
        } elseif (is_array($rawReward)) {
            $reward = $rawReward;
        }

        // Step 3: 若還是解析不到或不為陣列，直接回傳空陣列
        if (! is_array($reward)) {
            return [];
        }

        // Step 4: 統一轉換成 [['item_id'=>x, 'amount'=>y]]
        $formatted = [];

        // 單筆獎勵 [id, amount]
        if (count($reward) === 2 && is_numeric($reward[0] ?? null)) {
            return [[
                'item_id' => (int) $reward[0],
                'amount' => (int) $reward[1],
            ]];
        }

        // 多筆獎勵 [[id, amount], [id, amount], ...]
        foreach ($reward as $r) {
            if (is_array($r) && count($r) === 2 && is_numeric($r[0] ?? null) && is_numeric($r[1] ?? null)) {
                $formatted[] = [
                    'item_id' => (int) $r[0],
                    'amount' => (int) $r[1],
                ];
            }
        }

        return $formatted;
    }
}
