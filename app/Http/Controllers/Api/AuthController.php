<?php
namespace App\Http\Controllers\Api;

use App\Models\CharacterDeploySlot;
use App\Models\EmailValids;
use App\Models\GddbSurgameGrade;
use App\Models\UserCharacter;
use App\Models\UserEquipments;
use App\Models\UserItemLogs;
use App\Models\UserItems;
use App\Models\UserMaps;
use App\Models\UserPet;
use App\Models\Users;
use App\Models\UserSettings;
use App\Models\UserSlotEquipment;
use App\Models\UserSurGameInfo;
use App\Service\BlocklistService;
use App\Service\DeploySlotService;
use App\Service\ErrorService;
use App\Service\FollowService;
use App\Service\GradeTaskService;
use App\Service\TalentService;
use App\Service\TaskService;
use App\Service\UserItemService;
use App\Service\UserPetService;
use App\Service\UserService;
use App\Service\UserStatsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Validator;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct(Request $request)
    {
        $origin         = $request->header('Origin');
        $referer        = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => ['register', 'firebase_login', 'create_user_login', 'uid_login', 'mac_login', 'login', 'checkRegistrEmailValid', 'sendEmailValid', 'web_firebase_login', 'userSurgameInfo']]);
        }
    }

    public function firebase_login(Request $request)
    {
        $data = $request->input();
        \Log::info('[firebase_login] 請求資料: ' . json_encode($data));

        // 驗證必要欄位
        $requiredFields = ['firebase_uid', 'firebase_name', 'firebase_providerId', 'firebase_accessToken'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return response()->json(['message' => __($field . '錯誤'), 'field' => $field], 422);
            }
        }

        // 嘗試找出使用者（包含軟刪除）
        $user = Users::withTrashed()->where('firebase_uid', $data['firebase_uid'])->first();

        // 如果是軟刪除帳號 → 恢復並補資料
        if ($user && $user->trashed()) {
            $user->restore();
            $this->restoreUserPet($user);
            \Log::info('[firebase_login] 從軟刪除恢復使用者: ' . $user->uid);
        }

        // 建立新帳號
        if (! $user) {
            $user                        = new Users;
            $user->account               = base64_encode(Carbon::now()->timestamp . rand(10000, 99999));
            $user->password              = Carbon::now()->timestamp . rand(10000, 99999);
            $user->email                 = $data['email'] ?? null;
            $user->firebase_name         = $data['firebase_name'];
            $user->firebase_uid          = $data['firebase_uid'];
            $user->firebase_provider_id  = $data['firebase_providerId'];
            $user->firebase_access_token = $data['firebase_accessToken'];
            $user->firebase_photo_url    = $data['firebase_photoURL'] ?? null;
            $user->is_active             = 1;
            $user->save();
            \Log::info('[firebase_login] 新增使用者成功: ' . json_encode($user));

            $message = __('創建並登入成功');
        } else {
            if (empty($user->is_active)) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0003'), 401);
            }

            // 補更新欄位
            $user->firebase_access_token = $data['firebase_accessToken'];
            $user->firebase_photo_url    = $data['firebase_photoURL'] ?? $user->firebase_photo_url;

            if ($user->isDirty()) {
                $user->save();
                \Log::info('[firebase_login] 使用者資訊已更新: ' . json_encode($user->getDirty()));
            } else {
                \Log::info('[firebase_login] 使用者無需更新: ' . $user->uid);
            }

            $message = __('登入成功');
        }

        // 初次登入贈送邏輯
        if (empty($user->new_give)) {
            $result = UserService::init_create_give($user->id);
            \Log::info('[firebase_login] 初次登入贈送結果: ' . json_encode($result));
        }

        // 發 Token
        $token = auth()->guard('api')->login($user);
        UserService::addLoginLog($user->uid, $request->ip(), 'firebase', $data);
        \Log::info('[firebase_login] 登入成功 UID: ' . $user->uid);

        // 任務處理
        $taskService      = new TaskService();
        $userStatsService = new UserStatsService($taskService);
        $taskStatsService = new UserStatsService($taskService, $taskService->keywords(), [$taskService, 'calculateStat']);

        $userStatsService->updateByKeyword($user, 'login');
        $taskStatsService->updateByKeyword($user, 'login');
        $taskStatsService->updateByKeyword($user, 'login_event');
        $taskStatsService->updateByKeyword($user, 'newbie');

        $completedTask       = $taskService->getCompletedTasks($user->uid);
        $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);

        // 創建surgame
        if (empty(UserSurGameInfo::where('uid', $user->uid)->first())) {
            UserSurGameInfo::createInitialData($user->uid);
        }

        // 檢查是否有主角Surgame資料
        if (! $this->checkUserCharacter($user->uid)) {
            $this->createMainCharacter($user->uid);
        }

        // 檢查是否有陣位資料
        if (! $this->checkUserDeploySlot($user->uid)) {
            $this->createMainCharacterDeploySlot($user->uid);
        }

        // 檢查是否有精煉/強化資料
        if (! $this->checkUserDeploySlotEquip($user->uid)) {
            $this->initSlotEquip($user->uid);
        }

        // 檢查是否需要初始化天賦資料
        $talentService = new TalentService();
        if (! $talentService->checkTalentPoolExists($user->uid, 1)) {
            $talentService->createTalentPool($user->uid, 1);
        }

        return response()->json([
            'message'      => $message,
            'data'         => $this->createNewToken($token),
            'finishedTask' => $formattedTaskResult,
        ], 200);
    }

    //220250114 後續已經沒有使用
    public function create_user_login(Request $request)
    {
        $user           = new Users;
        $user->account  = Carbon::now()->timestamp . rand(10000, 99999);
        $user->password = Carbon::now()->timestamp . rand(10000, 99999);
        $user->save();

        $user           = Users::find($user->id);
        $user->account  = base64_encode($user->uid);
        $user->password = $user->uid;
        $user->new_give = 1;
        $user->save();

        $token = auth()->guard('api')->login($user);
        if ($user) {
            // 2025/05/23 新增訪客登入新手禮包
            if (empty($user->new_give)) {
                $result = UserService::init_create_give($user->id);
                \Log::info(json_encode($result));
            }

            return response()->json([
                'message' => __('創建並登入成功'),
                'data'    => $this->createNewToken($token),
            ], 200);
        }
    }

    public function uid_login(Request $request)
    {
        $data = $request->input();
        if (empty($data['uid'])) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $user = Users::withTrashed()->where('uid', $data['uid'])->first();
        if ($user && $user->trashed()) {
            $user->restore();
            $this->restoreUserPet($user);
        }

        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        if (empty($user->is_active)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0003'), 401);
        }

        $userEquipment = UserEquipments::where('user_id', $user->id)->first();
        if (empty($userEquipment)) {
            $userEquipment          = new UserEquipments;
            $userEquipment->user_id = $user->id;
            $userEquipment->save();
        }

        // 2025/05/23 新增訪客登入新手禮包
        if (empty($user->new_give)) {
            $result = UserService::init_create_give($user->id);
            \Log::info(json_encode($result));
        }

        $token = auth()->guard('api')->login($user);
        UserService::addLoginLog($user->uid, $request->ip(), 'uid', $data);

        //============ 任務系統 ============
        // 任務Service
        $taskService = new TaskService();
        // 建立今天的每日任務
        $taskService->autoAssignTasks($user->uid);
        // 紀錄系統任務
        $userStatsService = new UserStatsService($taskService);
        $userStatsService->updateByKeyword($user, 'login');
        // 玩家任務
        $taskStatsService = new UserStatsService($taskService, $taskService->keywords(), [$taskService, 'calculateStat']);
        $taskStatsService->updateByKeyword($user, 'login');
        $taskStatsService->updateByKeyword($user, 'login_event');
        $taskStatsService->updateByKeyword($user, 'newbie');
        // 本次登入是否有完成任務
        $completedTask       = $taskService->getCompletedTasks($user->uid);
        $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);
        //============ 任務系統 ============

        // 創建surgame
        if (empty(UserSurGameInfo::where('uid', $user->uid)->first())) {
            UserSurGameInfo::createInitialData($user->uid);
        }

        // 給予軍階任務
        $userSurgameInfo = UserSurGameInfo::where('uid', $user->uid)->first();
        $gradeSerivce    = new GradeTaskService();
        $gradeSerivce->autoAsignGradeTask($userSurgameInfo);

        // 檢查是否有主角Surgame資料
        if (! $this->checkUserCharacter($user->uid)) {
            $this->createMainCharacter($user->uid);
        }

        // 檢查是否有陣位資料
        if (! $this->checkUserDeploySlot($user->uid)) {
            $this->createMainCharacterDeploySlot($user->uid);
        }

        // 檢查是否有精煉/強化資料
        if (! $this->checkUserDeploySlotEquip($user->uid)) {
            $this->initSlotEquip($user->uid);
        }
        // 檢查是否需要初始化天賦資料
        $talentService = new TalentService();
        if (! $talentService->checkTalentPoolExists($user->uid, 1)) {
            $talentService->createTalentPool($user->uid, 1);
        }

        // 登入判斷
        if ($user) {
            return response()->json([
                'message'      => __('登入成功'),
                'data'         => $this->createNewToken($token),
                'finishedTask' => $formattedTaskResult,
            ], 200);
        }
    }

    public function mac_login(Request $request)
    {
        $data = $request->input();
        \Log::info(json_encode($data));

        if (empty($data['mac_id'])) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0007'), 422);
        }

        $user = Users::withTrashed()->where('mac_id', $data['mac_id'])->first();
        if ($user && $user->trashed()) {
            $user->restore();
            $this->restoreUserPet($user);
        }
        if (empty($user)) {
            $user           = new Users;
            $user->mac_id   = $data['mac_id'];
            $user->account  = base64_encode($data['mac_id']);
            $user->password = $data['mac_id'];
            $user->save();

            $user = Users::find($user->id);
        }
        if (empty($user->is_active)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0003'), 401);
        }

        // 2025/05/23 新增訪客登入新手禮包
        if (empty($user->new_give)) {
            $result = UserService::init_create_give($user->id);
            \Log::info(json_encode($result));
        }

        $token = auth()->guard('api')->login($user);
        UserService::addLoginLog($user->uid, $request->ip(), 'mac', $data);

        //============ 任務系統 ============
        // 任務Service
        $taskService = new TaskService();
        // 紀錄系統任務
        $userStatsService = new UserStatsService($taskService);
        $userStatsService->updateByKeyword($user, 'login');
        // 玩家任務
        $taskStatsService = new UserStatsService($taskService, $taskService->keywords(), [$taskService, 'calculateStat']);
        $taskStatsService->updateByKeyword($user, 'login');
        $taskStatsService->updateByKeyword($user, 'login_event');
        $taskStatsService->updateByKeyword($user, 'newbie');
        // 本次登入是否有完成任務
        $completedTask       = $taskService->getCompletedTasks($user->uid);
        $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);
        //============ 任務系統 ============

        // 創建surgame
        if (empty(UserSurGameInfo::where('uid', $user->uid)->first())) {
            UserSurGameInfo::createInitialData($user->uid);
        }

        // 檢查是否有主角Surgame資料
        if (! $this->checkUserCharacter($user->uid)) {
            $this->createMainCharacter($user->uid);
        }

        // 檢查是否有陣位資料
        if (! $this->checkUserDeploySlot($user->uid)) {
            $this->createMainCharacterDeploySlot($user->uid);
        }

        // 檢查是否有精煉/強化資料
        if (! $this->checkUserDeploySlotEquip($user->uid)) {
            $this->initSlotEquip($user->uid);
        }

        // 檢查是否需要初始化天賦資料
        $talentService = new TalentService();
        if (! $talentService->checkTalentPoolExists($user->uid, 1)) {
            $talentService->createTalentPool($user->uid, 1);
        }

        if ($user) {
            return response()->json([
                'message'      => __('登入成功'),
                'data'         => $this->createNewToken($token),
                'finishedTask' => $formattedTaskResult,
            ], 200);
        }
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $data = $request->input();
        \Log::info(json_encode($data));
        $validator = Validator::make($data, [
            'account'  => 'required',
            'password' => 'required',
        ]);

        if (empty($data['account'])) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0001'), 422);
        }

        if (empty($data['password'])) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0002'), 422);
        }

        $user = Users::withTrashed()->where('account', $data['account'])->first();
        if ($user && $user->trashed()) {
            $user->restore();
            $this->restoreUserPet($user);
        }
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0001'), 401);
        } elseif (! Hash::check($data['password'], $user->password)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0002'), 401);
        } elseif (empty($user->is_active)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0003'), 401);
        }

        $token = auth()->guard('api')->login($user);
        UserService::addLoginLog($user->uid, $request->ip(), 'other', $data);

        //============ 任務系統 ============
        // 任務Service
        $taskService = new TaskService();
        // 紀錄系統任務
        $userStatsService = new UserStatsService($taskService);
        $userStatsService->updateByKeyword($user, 'login');
        // 玩家任務
        $taskStatsService = new UserStatsService($taskService, $taskService->keywords(), [$taskService, 'calculateStat']);
        $taskStatsService->updateByKeyword($user, 'login');
        $taskStatsService->updateByKeyword($user, 'login_event');
        $taskStatsService->updateByKeyword($user, 'newbie');
        // 本次登入是否有完成任務
        $completedTask       = $taskService->getCompletedTasks($user->uid);
        $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);
        //============ 任務系統 ============

        // 創建surgame
        if (empty(UserSurGameInfo::where('uid', $user->uid)->first())) {
            UserSurGameInfo::createInitialData($user->uid);
        }

        if ($user) {
            return response()->json([
                'message'      => __('登入成功'),
                'data'         => $this->createNewToken($token),
                'finishedTask' => $formattedTaskResult,
            ], 200);
        }
    }
    /**
     * Register a Users.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account'  => 'required',
            'password' => 'required',
        ]);

        $data = $request->input();

        if (empty($data['account']) || strlen($data['password']) < 4) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0001'), 422);
        }

        if (Users::where('account', $data['account'])->count()) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0004'), 401);
        }

        if (empty($data['password']) || strlen($data['password']) < 4) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0002'), 422);
        }

        $user           = new Users;
        $user->account  = $data['account'];
        $user->password = $data['password'];
        $user->save();

        $user = Users::find($user->id);

        $token = auth()->guard('api')->login($user);

        // 創建surgame
        if (empty(UserSurGameInfo::where('uid', $user->uid)->first())) {
            UserSurGameInfo::createInitialData($user->uid);
        }

        // 檢查是否有主角Surgame資料
        if (! $this->checkUserCharacter($user->uid)) {
            $this->createMainCharacter($user->uid);
        }

        // 檢查是否有陣位資料
        if (! $this->checkUserDeploySlot($user->uid)) {
            $this->createMainCharacterDeploySlot($user->uid);
        }

        // 檢查是否有精煉/強化資料
        if (! $this->checkUserDeploySlotEquip($user->uid)) {
            $this->initSlotEquip($user->uid);
        }
        if ($user) {
            return response()->json([
                'message' => __('註冊並登入成功'),
                'data'    => $this->createNewToken($token),
            ], 200);
        }
    }
    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth()->guard('api')->logout();
        return response()->json(['message' => __('登出成功')], 200);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return response()->json(['data' => $this->createNewToken(auth()->guard('api')->refresh())], 200);
    }
    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function userProfile()
    {
        $user = auth()->guard('api')->user();
        $user->load('userEquipment');
        $user->change_name_item_id             = 300; // 改名道具item_id
        $user->change_name_cnt                 = UserItems::where('item_id', 300)->first()?->qty ?? 0;
        $user->change_name_currency_item_id    = 100; // 改名道具貨幣item_id
        $user->change_name_currency_item_count = UserItems::where('item_id', 100)->first()?->qty ?? 0;
        $user->change_name_currency_item_price = 10;
        return response()->json(['data' => $user], 200);
    }

    // 取得玩家資訊
    public function userInfo($uid)
    {
        $currentUser = auth()->guard('api')->user();
        if (empty($currentUser)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        $otherUser = Users::with('userEquipment')->where('uid', $uid)->first();
        if (empty($otherUser)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        $otherUser->load('userEquipment');
        $otherUser->is_black_list = BlocklistService::isBlocked($currentUser->uid, $uid);

        $followService           = new FollowService();
        $otherUser->is_following = $followService->isFollowing($currentUser->uid, $uid);
        $formattedInfoResult     = $this->formatUserInfo($otherUser);

        // 創建surgame
        if (empty(UserSurGameInfo::where('uid', $currentUser->uid)->first())) {
            UserSurGameInfo::createInitialData($currentUser->uid);
        }

        // 檢查是否有主角Surgame資料
        if (! $this->checkUserCharacter($currentUser->uid)) {
            $this->createMainCharacter($currentUser->uid);
        }

        // 檢查是否有陣位資料
        if (! $this->checkUserDeploySlot($currentUser->uid)) {
            $this->createMainCharacterDeploySlot($currentUser->uid);
            $this->initSlotEquip($currentUser->uid);
        }

        return response()->json(['data' => $formattedInfoResult], 200);
    }

    public function userHomeMap()
    {
        $userMap = UserService::getHomeMap(auth()->guard('api')->user()->id);

        if (! $userMap) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'MAP:0003'), 404);
        }

        return response()->json(['data' => $userMap], 200);
    }

    public function userLevel()
    {
        $level_data = UserService::getLevelData(auth()->guard('api')->user()->id);

        if (! $level_data) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'LEVEL:0001'), 404);
        }

        return response()->json(['data' => $level_data], 200);
    }

    public function userLevelPass(Request $request)
    {
        $data = $request->input();
        $user = Users::find(auth()->guard('api')->user()->id);
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0001'), 404);
        }
        if (empty($data['level']) || empty($data['sub_level']) || empty($data['section'])) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'LEVEL:0002'), 404);
        }

        $userSetting = UserSettings::where('user_id', auth()->guard('api')->user()->id)->first();
        if (empty($userSetting)) {
            UserSettings::init(auth()->guard('api')->user()->id);
            $userSetting = UserSettings::where('user_id', auth()->guard('api')->user()->id)->first();
        }
        $level_data = UserService::getLevelData(auth()->guard('api')->user()->id);
        if (isset($level_data['level_' . $data['level'] . $data['sub_level']])) {
            $proxy_data = $level_data['level_' . $data['level'] . $data['sub_level']];

            $proxy_data['now'] = $data['section'];
            if ($proxy_data['now'] > $proxy_data['max']) {
                $proxy_data['max'] = $proxy_data['now'];
            }
            if (! isset($proxy_data['sections']['section_' . $data['section']])) {
                $proxy_data['sections']['section_' . $data['section']] = [
                    "section" => $data['section'],
                    "kill"    => 0,
                    "second"  => 0,
                ];
            }
            if (! empty($data['kill'])) {
                if ($proxy_data['sections']['section_' . $data['section']]['kill'] < $data['kill']) {
                    $proxy_data['sections']['section_' . $data['section']]['kill'] = $data['kill'];
                }
            }
            if (! empty($data['second'])) {
                if ($proxy_data['sections']['section_' . $data['section']]['second'] == 0 || $proxy_data['sections']['section_' . $data['section']]['second'] > $data['second']) {
                    $proxy_data['sections']['section_' . $data['section']]['second'] = $data['second'];
                }
            }
            $proxy_data['section_cnt']                                  = count($proxy_data['sections']);
            $level_data['level_' . $data['level'] . $data['sub_level']] = $proxy_data;

            $userSetting->level_data = json_encode($level_data);
            $userSetting->save();
        }

        return response()->json(['data' => $level_data], 200);
    }

    public function itemAvatars(Request $request)
    {
        $data = $request->input();
        $user = Users::find(auth()->guard('api')->user()->id);
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0001'), 404);
        }

        $item_ids    = [];
        $manager_ids = [];

        if ($user->is_admin) {
            $userItems = UserItemService::getItemLists();
            foreach ($userItems as $userItem) {

                if (! empty($userItem['region']) && $userItem['region'] == UserItems::REGION_AVATAR) {
                    $item_ids[]    = (int) $userItem['item_id'];
                    $manager_ids[] = (int) $userItem['manager_id'];
                }
            }
        } else {
            $userItems = UserItems::where('user_id', $user->id)->where('region', UserItems::REGION_AVATAR)->get();
            foreach ($userItems as $userItem) {
                $item_ids[] = $userItem->item_id;
                if ($userItem->manager_id > 0) {
                    $manager_ids[] = $userItem->manager_id;
                }

            }
        }

        return response()->json(['data' => ['item_ids' => $item_ids, 'manager_ids' => $manager_ids]], 200);

    }

    public function itemMaps(Request $request)
    {
        $data = $request->input();
        $user = Users::find(auth()->guard('api')->user()->id);
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0001'), 404);
        }

        $item_ids    = [];
        $manager_ids = [];
        $qties       = [];

        if ($user->is_admin) {
            $userItems = UserItemService::getItemLists();
            foreach ($userItems as $userItem) {
                if (! empty($userItem['region']) && $userItem['region'] == UserItems::REGION_MAP) {
                    $item_ids[]    = (int) $userItem['item_id'];
                    $manager_ids[] = (int) $userItem['manager_id'];
                    $qties[]       = 999;
                }
            }
        } else {
            $userItems = UserItems::where('user_id', $user->id)->where('region', UserItems::REGION_MAP)->get();
            foreach ($userItems as $userItem) {
                $item_ids[]    = $userItem->item_id;
                $manager_ids[] = $userItem->manager_id ?: '';
                $qties[]       = $userItem->qty ?: 0;
            }
        }

        return response()->json(['data' => ['item_ids' => $item_ids, 'manager_ids' => $manager_ids, 'qties' => $qties]], 200);

    }

    public function itemMoneys(Request $request)
    {
        $data = $request->input();
        $user = Users::find(auth()->guard('api')->user()->id);
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0001'), 404);
        }

        $item_ids = [100, 101, 102, 199, 200, 201, 202];

        $result = [
            'item_ids' => [],
            'values'   => [],
        ];

        $userItems = UserItems::where('user_id', $user->id)->whereIn('item_id', $item_ids)->get();
        foreach ($item_ids as $index => $item_id) {
            $result['item_ids'][] = $item_id;

            $userItem = $userItems->where('item_id', $item_id)->first();
            if ($userItem) {
                $result['values'][] = $userItem->qty;
            } else {
                $result['values'][] = 0;
            }
        }

        // ============= 任務系統 ============
        $taskService         = new TaskService();
        $completedTask       = $taskService->getCompletedTasks($user->uid);
        $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);
        // ============= 任務系統 ============

        return response()->json(['data' => ['moneys' => $result], 'finishedTask' => $formattedTaskResult], 200);

    }

    public function updateUserProfile(Request $request)
    {
        $data = $request->input();

        $user = Users::find(auth()->guard('api')->user()->id);

        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0001'), 404);
        }
        foreach ($data as $key => $value) {
            switch ($key) {
                case 'name':
                    if (empty($value)) {
                        return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0013'), 422);
                    }
                    if (Users::whereRaw('LOWER(name) = LOWER(?)', [$value])->where('id', '!=', $user->id)->count()) {
                        return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0014'), 422);
                    }

                    if (isset($data['type']) && $data['type'] == 'change_name') {

                        // 先檢查改名卡
                        $changeNameItem = $user->userItems()->where('item_id', 300)->first();
                        $changeNameCnt  = $changeNameItem?->qty ?? 0;

                        if ($changeNameCnt > 0) {
                            // 用改名卡
                            $results = UserItemService::removeItem(UserItemLogs::TYPE_ITEM_USE, $user->id, $user->uid, 300, 1, 1, '玩家改名字消耗改名卡');
                            if (empty($results) || $results['success'] != 1) {
                                return response()->json(ErrorService::errorCode(__METHOD__, $results['error_code'] ?? 'SYSTEM:0003'), 500);
                            }
                            // 改名
                            $user->name = $value;
                            $user->save();
                            unset($data['name']);
                        } else {
                            // 檢查商城幣
                            $coinItem = $user->userItems()->where('item_id', 100)->first();
                            $coinCnt  = $coinItem?->qty ?? 0;

                            if ($coinCnt > 10) {
                                // 扣商城幣
                                $results = UserItemService::removeItem(UserItemLogs::TYPE_ITEM_USE, $user->id, $user->uid, 100, 10, 1, '玩家改名字消耗商城幣');
                                if (empty($results) || $results['success'] != 1) {
                                    return response()->json(ErrorService::errorCode(__METHOD__, $results['error_code'] ?? 'SYSTEM:0003'), 500);
                                }
                                // 改名
                                $user->name = $value;
                                $user->save();
                                unset($data['name']);
                            } else {
                                // 兩個都不夠
                                return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0015'), 422);
                            }
                        }
                    }

                    break;

                default:
                    # code...
                    break;
            }
        }
        $user->fill($data);
        if ($user->save()) {
            // 檢查是否有地圖
            if (! UserMaps::where('user_id', $user->id)->exists()) {
                UserService::getHomeMap($user->id);
            }

            //============ 任務系統 ============
            // 任務Service
            $taskService = new TaskService();
            // 玩家任務
            $taskStatsService = new UserStatsService($taskService, $taskService->keywords(), [$taskService, 'calculateStat']);
            $taskStatsService->updateByKeyword($user, 'newbie');
            $taskStatsService->updateByKeyword($user, 'mall_coin');
            // 本次登入是否有完成任務
            $completedTask       = $taskService->getCompletedTasks($user->uid);
            $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);
            //============ 任務系統 ============

            //
            $user->change_name_item_id             = 300;
            $user->change_name_cnt                 = UserItems::where('item_id', 300)->first()?->qty ?? 0;
            $user->change_name_currency_item_id    = 100;
            $user->change_name_currency_item_count = UserItems::where('item_id', 100)->first()?->qty ?? 0;
            $user->change_name_currency_item_price = 10;

            // 創建surgame
            if (empty(UserSurGameInfo::where('uid', $user->uid)->first())) {
                UserSurGameInfo::createInitialData($user->uid);
            }

            // 檢查是否有主角陣位
            if (! $this->checkUserCharacter($user->uid)) {
                $this->createMainCharacter($user->uid);
            }

            return response()->json([
                'data'         => $user,
                'finishedTask' => $formattedTaskResult,
            ], 200);
        } else {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 500);
        }
    }

    // 刪除帳號
    public function deleteAccount(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        $user = Users::where('uid', $uid)->first();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        // 先刪除寵物
        $userPets = UserPetService::getPets($user->uid);
        if (! empty($userPets)) {
            foreach ($userPets as $userPet) {
                $userPet->delete();
            }
        }

        // 刪除關卡相關資訊
        if (! empty(UserSurGameInfo::where('uid', $user->uid)->first())) {
            $surgameUserInfo = UserSurGameInfo::where('uid', $user->uid)->first();
            if ($surgameUserInfo) {
                $surgameUserInfo->delete();
            }
        }

        if ($user->delete()) {
            $data = [
                'deleted_at' => Carbon::parse($user->deleted_at)->addDays(30)->timestamp,
            ];
            return response()->json(['message' => __('刪除成功'), 'data' => $data], 200);
        } else {

            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 500);
        }
    }

    public function sendSmsValid(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $request->input();

        if (empty($data['mobile'])) {
            return response()->json(['message' => __('電話號碼錯誤')], 422);
        }

        // $pattern = /^(5|6|8|9)\d{7}$/;
        // if(!preg_match($pattern, $data['mobile']))  return response()->json(['message' => __('電話號碼錯誤'), ], 422);
        $userSmsValid = UserSmsValids::where('user_id', auth()->guard('api')->user()->id)->orderby('id', 'desc')->first();

        $currentDate = Carbon::now();
        if (! empty($userSmsValid) && $userSmsValid->sms_valid_limit && $currentDate->diffInMinutes($userSmsValid->sms_valid_limit) <= 5) {
            return response()->json(['message' => __('5分鐘內不可重新發送驗證碼')], 422);
        }

        if (empty($userSmsValid)) {
            $userSmsValid          = new UserSmsValids;
            $userSmsValid->user_id = auth()->guard('api')->user()->id;
            $userSmsValid->mobile  = $data['mobile'];
        }
        $userSmsValid->sms_valid_code  = '888888';
        $userSmsValid->sms_valid_limit = Carbon::now()->addMinutes(5);
        if ($userSmsValid->save()) {
            return response()->json(['message' => __('發送成功')], 200);
        }

        return response()->json(['message' => __('資料錯誤')], 404);
    }

    public function checkSmsValid(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $data = $request->input();

        $userSmsValid = UserSmsValids::where('user_id', auth()->guard('api')->user()->id)->orderby('id', 'desc')->first();

        if (empty($userSmsValid)) {
            return response()->json(['message' => __('查無驗證碼')], 422);
        }

        $currentDate = Carbon::now();
        if ($userSmsValid->sms_valid_limit && $currentDate->diffInMinutes($userSmsValid->sms_valid_limit) > 5) {
            return response()->json(['message' => __('驗證碼已超過5分鐘有效時間')], 422);
        }

        if (empty($data['code']) || $data['code'] != $userSmsValid->sms_valid_code) {
            return response()->json(['message' => __('驗證碼錯誤')], 422);
        }

        if ($userSmsValid->delete()) {
            return response()->json(['message' => __('驗證成功')], 200);
        }

        return response()->json(['message' => __('資料錯誤')], 404);
    }

    public function sendEmailValid(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $request->input();
        // $pattern = /^(5|6|8|9)\d{7}$/;
        if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => __('Email格式錯誤')], 422);
        }

        // $emailValid = EmailValids::where('email',$data['email'])->orderby('id','desc')->first();

        $currentDate = Carbon::now();
        // if(!empty($emailValid) && $emailValid->sms_valid_limit && $currentDate->diffInMinutes($emailValid->sms_valid_limit) <= 5) return response()->json(['message' => __('5分鐘內不可重新發送驗證碼'), ], 422);

        $emailValid              = new EmailValids;
        $emailValid->email       = $data['email'];
        $emailValid->valid_code  = rand(100000, 999999);
        $emailValid->valid_limit = Carbon::now()->addMinutes(5);
        if ($emailValid->save()) {
            try {
                \App\Service\MailService::send($emailValid->email, '您的驗證碼:' . $emailValid->valid_code, config('services.SITE_NAME'));
            } catch (Exception $ex) {
                // Debug via $ex->getMessage();
                return response()->json(['message' => __('發送失敗')], 404);
            }
            return response()->json(['message' => __('發送成功')], 200);
        }

        return response()->json(['message' => __('資料錯誤')], 404);
    }

    public function checkRegistrEmailValid(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required',
            'code'     => 'required',
            'password' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $data = $request->input();

        if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => __('Email格式錯誤')], 422);
        }

        $user = StoreCustomers::where('email', $data['email'])->first();
        if (! empty($user)) {
            return response()->json(['message' => __('此Email已存在，請直接登入')], 422);
        }

        $emailValid = EmailValids::where('email', $data['email'])->orderby('id', 'desc')->first();

        if (empty($emailValid)) {
            return response()->json(['message' => __('查無驗證碼')], 422);
        }

        $currentDate = Carbon::now();
        if ($emailValid->valid_limit && $currentDate->diffInMinutes($emailValid->valid_limit) > 5) {
            return response()->json(['message' => __('驗證碼已超過5分鐘有效時間')], 422);
        }

        if (empty($data['code']) || $data['code'] != $emailValid->valid_code) {
            return response()->json(['message' => __('驗證碼錯誤')], 422);
        }

        if ($emailValid->delete()) {
            $user           = new StoreCustomers;
            $user->store_id = $data['store_id'] ?? 1;
            $user->account  = $data['email'];
            $user->email    = $data['email'];
            $user->password = $data['password'];
            $user->save();

            $token = auth()->guard('api')->login($user);
            if ($user) {
                return response()->json([
                    'message' => __('驗證登入成功'),
                    'data'    => $this->createNewToken($token),
                ], 200);
            }
        }

        return response()->json(['message' => __('資料錯誤')], 404);
    }

    /** 玩家改名 */
    public function changeName(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $request->input();

        $user = Users::find(auth()->guard('api')->user()->id);
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0001'), 404);
        }

        $user->name = $data['name'];
        $user->save();

        return response()->json([
            'message' => __('改名成功'),
            'data'    => $user,
        ], 200);
    }

    // 復原使用者的寵物
    private function restoreUserPet($user)
    {
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0001'), 404);
        }

        $userPets = UserPet::withTrashed()
            ->where('uid', $user->uid)
            ->whereNotNull('deleted_at')
            ->get();

        $restoredPets = collect();

        if ($userPets) {
            foreach ($userPets as $userPet) {
                if ($userPet->trashed()) {
                    $userPet->restore();
                    $restoredPets->push($userPet);
                }
            }

            return [
                'success' => true,
                'message' => __('寵物復原成功'),
                'data'    => $restoredPets,
            ];
        }

        return [
            'success' => false,
            'message' => __('沒有可復原的寵物'),
        ];
    }

    // 玩家資訊回傳格式
    private function formatUserInfo($user)
    {
        $blockStatus = $user->is_black_list ? 1 : 0;
        $isFollowing = $user->is_following ? 1 : 0;
        return [
            'uid'                => $user->uid,
            'name'               => $user->name,
            'firebase_photo_url' => $user->firebase_photo_url,
            'last_login_time'    => $user->last_login_time,
            'is_black_list'      => $blockStatus,
            'is_following'       => $isFollowing,
            'user_equipment'     => $user->userEquipment,
        ];
    }

    protected function createNewToken($token)
    {
        return [
            'access_token' => $token,
            'token_type'   => 'bearer',
            /** @var int */
            'expires_in'   => auth()->guard('api')->factory()->getTTL() * 60,
            /** @var object */
            'user'         => tap(auth()->guard('api')->user()->load('userEquipment'), function ($user) {
                $user->change_name_item_id             = 300;
                $user->change_name_cnt                 = \App\Models\UserItems::where('item_id', 300)->first()?->qty ?? 0;
                $user->change_name_currency_item_id    = 100;
                $user->change_name_currency_item_count = \App\Models\UserItems::where('item_id', 100)->first()?->qty ?? 0;
                $user->change_name_currency_item_price = 10;
            }),
        ];
    }

    // 官網firebase登入
    public function web_firebase_login(Request $request)
    {
        $data = $request->only([
            'firebase_uid',
            'firebase_name',
            'firebase_providerId',
            'firebase_accessToken',
            'firebase_photoURL',
            'email',
        ]);

        \Log::info('[web_firebase_login] 請求資料', $data);

        // 使用 Laravel 驗證器處理欄位驗證
        $validator = \Validator::make($data, [
            'firebase_uid'         => 'required|string',
            'firebase_providerId'  => 'required|string',
            'firebase_accessToken' => 'required|string',
            'email'                => 'required|email',
        ]);

        if ($validator->fails()) {
            $errors     = $validator->errors();
            $firstField = array_keys($errors->toArray())[0];

            \Log::warning('[web_firebase_login] 欄位驗證失敗', [
                'field'   => $firstField,
                'errors'  => $errors->toArray(),
                'request' => $data,
            ]);

            return response()->json([
                'success' => false,
                'message' => "{$firstField} 欄位錯誤",
                'field' => $firstField,
            ], 422);
        }

        // 嘗試取得帳號（含軟刪除）
        $user = Users::withTrashed()->where('firebase_uid', $data['firebase_uid'])->first();

        if ($user && $user->trashed()) {
            $user->restore();
            $this->restoreUserPet($user);
            \Log::info('[web_firebase_login] 從軟刪除恢復使用者', ['uid' => $user->uid]);
        }

        if (! $user) {
            $user = new Users([
                'account'               => base64_encode(Carbon::now()->timestamp . rand(10000, 99999)),
                'password'              => Carbon::now()->timestamp . rand(10000, 99999),
                'email'                 => $data['email'],
                'firebase_name'         => $data['firebase_name'] ?? 'Apple 使用者',
                'firebase_uid'          => $data['firebase_uid'],
                'firebase_provider_id'  => $data['firebase_providerId'],
                'firebase_access_token' => $data['firebase_accessToken'],
                'firebase_photo_url'    => $data['firebase_photoURL'] ?? null,
                'is_active'             => 1,
            ]);

            $user->save();
            \Log::info('[web_firebase_login] 新增使用者成功', ['uid' => $user->uid]);
            $message = __('創建並登入成功');
        } else {
            if (! $user->is_active) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0003'), 401);
            }

            // 更新最新 token / avatar
            $user->firebase_access_token = $data['firebase_accessToken'];
            if (! empty($data['firebase_photoURL'])) {
                $user->firebase_photo_url = $data['firebase_photoURL'];
            }

            if ($user->isDirty()) {
                $user->save();
                \Log::info('[web_firebase_login] 使用者資訊已更新', $user->getDirty());
            } else {
                \Log::info('[web_firebase_login] 使用者無需更新', ['uid' => $user->uid]);
            }

            $message = __('登入成功');
        }

        // 發 JWT Token
        $token = auth()->guard('api')->login($user);
        \Log::info('[web_firebase_login] 登入成功', ['uid' => $user->uid, 'ip' => $request->ip()]);

        // surgame info 初始化
        if (empty(UserSurGameInfo::where('uid', $user->uid)->first())) {
            UserSurGameInfo::createInitialData($user->uid);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $this->createNewToken($token),
        ]);
    }

    // 取得玩家surgame資訊
    public function userSurgameInfo($uid)
    {
        // 先檢查查詢對象使用者是否存在
        $user = Users::where('uid', $uid)->first();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        $surgameInfo = UserSurGameInfo::where('uid', $uid)->first();
        if (empty($surgameInfo)) {
            $surgameInfo = UserSurGameInfo::createInitialData($uid);
        }
        $gradeAry = GddbSurgameGrade::get()->pluck('unique_id', 'related_level')->toArray();
        // 將軍階等級換成grade_manager_id
        if (isset($gradeAry[$surgameInfo->grade_level])) {
            $surgameInfo->grade_manager_id = $gradeAry[$surgameInfo->grade_level];
        }
        $surgameInfo = $surgameInfo->makeHidden(['grade_level']);

        return response()->json(['data' => $surgameInfo], 200);
    }

    // 創建玩家陣位資料
    public function createMainCharacter($uid)
    {
        // 先檢查查詢對象使用者是否存在
        $user = Users::where('uid', $uid)->first();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }
        try {
            // 創建主角在陣位內
            $userCharacter = UserCharacter::where('uid', $uid)->where('slot_index', 0)->first();
            if (empty($userCharacter)) {
                $userCharacter = UserCharacter::create([
                    'uid'          => $uid,
                    'slot_index'   => 0,
                    'character_id' => 0,
                    'start_level'  => 0,
                    'has_use'      => 1,
                ]);
            }
        } catch (Exception $e) {
            \Log::error('[createUserSurgame] 創建玩家初始資料失敗', [
                'uid'   => $uid,
                'error' => $e->getMessage(),
            ]);
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 500);
        }
    }

    public function createMainCharacterDeploySlot($uid)
    {
        // 先檢查查詢對象使用者是否存在
        $user = Users::where('uid', $uid)->first();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }
        try {
            // 創建五個陣位資料
            for ($i = 0; $i < 5; $i++) {
                $userDeploySlot = CharacterDeploySlot::where('uid', $uid)->where('position', $i)->first();
                if (empty($userDeploySlot)) {
                    $userDeploySlot = CharacterDeploySlot::create([
                        'uid'      => $uid,
                        'position' => $i,
                    ]);
                }
            }

        } catch (Exception $e) {
            \Log::error('[createUserDeploy] 創建玩家初始陣位資料失敗', [
                'uid'   => $uid,
                'error' => $e->getMessage(),
            ]);
            return response()->json(ErrorService::errorCode(__METHOD__, 'SYSTEM:0003'), 500);
        }
    }

    public function initSlotEquip($uid)
    {
        $svc = app(DeploySlotService::class);
        return $svc->initUserSlotEquipment($uid);
    }
    // 檢查是否有主角陣位
    public function checkUserCharacter($uid)
    {
        return UserCharacter::where('uid', $uid)->exists();
    }

    // 檢查是否有陣位資料
    public function checkUserDeploySlot($uid)
    {
        return CharacterDeploySlot::where('uid', $uid)->exists();
    }

    // 檢查陣位精煉資料(應該有5*6=30筆才對)
    public function checkUserDeploySlotEquip($uid)
    {
        return UserSlotEquipment::where('uid', $uid)->count() >= 30;
    }
}
