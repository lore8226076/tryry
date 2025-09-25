<?php

namespace App\Http\Controllers\Api;

use App\Service\ErrorService;
use App\Service\UserJourneyService;
use Illuminate\Http\Request;

class CharacterJourneyController extends Controller
{
    protected $journeyService;

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
        $wave = $request->input('wave');

        if (! is_numeric($chapterId) || (int) $chapterId <= 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'JOURNEY:0001'), 422);
        }

        if (! is_numeric($wave) || (int) $wave < 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'JOURNEY:0002'), 422);
        }

        // 取得獎勵id
        $rewardId = $this->journeyService->getRewardIdByChapterAndWave($chapterId, $wave);

        if (! $rewardId) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'JOURNEY:0003'), 422);
        }

        if (! $this->journeyService->canClaimReward($chapterId, $wave, $uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'JOURNEY:0004'), 422);
        }
        try {
            $result = $this->journeyService->claimChapterReward($uid, $rewardId);
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
                'wave' => $wave,
                'message' => $throwable->getMessage(),
            ]);

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * 解析請求來源的 UID
     */
    protected function resolveUid(Request $request): ?int
    {
        $authUser = auth()->guard('api')->user();

        if ($authUser?->uid) {
            return (int) $authUser->uid;
        }

        $uid = $request->input('uid', $request->query('uid'));

        return is_numeric($uid) ? (int) $uid : null;
    }
}
