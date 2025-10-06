<?php

namespace App\Http\Controllers\Api;

use App\Service\ErrorService;
use App\Service\StaminaService;
use App\Service\UserJourneyService;
use Illuminate\Http\Request;

class CharacterJourneyController extends Controller
{
    protected $journeyService;

    protected $userJourneyService;

    public function __construct(UserJourneyService $journeyService, Request $request)
    {
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

        $staminaResult = StaminaService::deductStamina($uid, 5, '主線關卡挑戰');
        if (empty($staminaResult['success'])) {
            return response()->json(ErrorService::errorCode(__METHOD__, $staminaResult['error_code']), 422);
        }

        $stamina = StaminaService::getStamina($uid);

        return response()->json(['data' => ['success' => true, 'stamina' => $stamina]]);
    }

    /**
     * 領取主線獎勵 (檢查邏輯待補)
     */
    public function claimMainReward(Request $request)
    {
        $user = $this->resolveUid($request, true);
        $uid = $user?->uid;
        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        $items = $request->input('items', []);
        // 轉成陣列
        if (is_string($items)) {
            $items = json_decode($items, true);
        }

        try {
            $result = $this->journeyService->claimReward($user, $items);
        } catch (\RuntimeException $exception) {
            $code = $exception->getMessage();

            if (is_string($code) && strpos($code, ':') !== false) {
                return response()->json(ErrorService::errorCode(__METHOD__, $code), 422);
            }

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 422);
        } catch (\Throwable $throwable) {
            \Log::error('主線獎勵領取失敗', [
                'uid' => $uid,
                'message' => $throwable->getMessage(),
            ]);

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 422);
        }

        $result = ['success' => true, 'items' => $items];

        return response()->json(['data' => $result]);
    }

    /**
     * 更新玩家章節進度
     */
    public function update(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $chapterId = $request->input('chapter_id');
        $wave = $request->input('wave');

        if (! is_numeric($chapterId) || (int) $chapterId <= 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'JOURNEY:0001'), 422);
        }

        if (! is_numeric($wave) || (int) $wave < 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'JOURNEY:0002'), 422);
        }

        // 檢查是否有此章節
        if (! $this->journeyService->findJourneyByIdentifier((int) $chapterId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'JOURNEY:0001'), 422);
        }

        try {
            $progress = $this->journeyService->updateJourneyProgress(
                $uid,
                (int) $chapterId,
                (int) $wave
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0002'), 422);
        }

        return response()->json(['data' => $progress]);
    }

    /**
     * 取得玩家當前章節進度
     */
    public function progress(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $progress = $this->journeyService->getCurrentProgress($uid);

        return response()->json(['data' => $progress]);
    }

    /**
     * 取得玩家章節獎勵狀態
     */
    public function rewards(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $rewards = $this->journeyService->getChapterRewards($uid);
        if (empty($rewards)) {
            $rewards = (object) [];
        }

        return response()->json(['data' => $rewards]);
    }

    /**
     * 領取章節獎勵
     */
    public function claimReward(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $chapterId = $request->input('chapter_id');

        if (! is_numeric($chapterId) || (int) $chapterId <= 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'JOURNEY:0001'), 422);
        }
        try {
            $result = $this->journeyService->claimChapterReward($uid, (int) $chapterId);
        } catch (\RuntimeException $exception) {
            $code = $exception->getMessage();

            if (is_string($code) && strpos($code, ':') !== false) {
                return response()->json(ErrorService::errorCode(__METHOD__, $code), 422);
            }

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 422);
        } catch (\Throwable $throwable) {
            \Log::error('章節獎勵領取失敗', [
                'uid' => $uid,
                'chapter_id' => $chapterId,
                'message' => $throwable->getMessage(),
            ]);

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 422);
        }

        return response()->json(['data' => $result]);
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
            $this->journeyService->resetJourneyRewards($uid);
        } catch (\Throwable $throwable) {
            \Log::error('章節獎勵重置失敗', [
                'uid' => $uid,
                'message' => $throwable->getMessage(),
            ]);

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 422);
        }

        return response()->json(['data' => ['success' => true]]);
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
}
