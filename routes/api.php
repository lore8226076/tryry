<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::group(['middleware' => 'api'], function ($router) {
    Route::group(['middleware' => 'api.key'], function ($router) {
        // API LOG 紀錄
        Route::post('/create_api_log', [App\Http\Controllers\Api\AdminsController::class, 'apiLog']);

        Route::group(['prefix' => 'auth'], function () {
            Route::post('/mac_login', [App\Http\Controllers\Api\AuthController::class, 'mac_login']);

            Route::post('/firebase_login', [App\Http\Controllers\Api\AuthController::class, 'firebase_login']);
            Route::post('/create_user_login', [App\Http\Controllers\Api\AuthController::class, 'create_user_login']);
            Route::post('/uid_login', [App\Http\Controllers\Api\AuthController::class, 'uid_login']);

            Route::post('/login', [App\Http\Controllers\Api\AuthController::class, 'login']);
            Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register']);
            Route::get('/user_profile', [App\Http\Controllers\Api\AuthController::class, 'userProfile']);
            Route::post('/user_update', [App\Http\Controllers\Api\AuthController::class, 'updateUserProfile']);

            Route::get('/user_home_map', [App\Http\Controllers\Api\AuthController::class, 'userHomeMap']);

            Route::get('/user_level', [App\Http\Controllers\Api\AuthController::class, 'userLevel']);
            Route::post('/user_level/pass', [App\Http\Controllers\Api\AuthController::class, 'userLevelPass']);

            Route::get('/item/avatars', [App\Http\Controllers\Api\AuthController::class, 'itemAvatars']);
            Route::get('/item/maps', [App\Http\Controllers\Api\AuthController::class, 'itemMaps']);
            Route::get('/item/moneys', [App\Http\Controllers\Api\AuthController::class, 'itemMoneys']);

            Route::post('/logout', [App\Http\Controllers\Api\AuthController::class, 'logout']);
            Route::post('/refresh', [App\Http\Controllers\Api\AuthController::class, 'refresh']);

            Route::post('/sendEmailValid', [App\Http\Controllers\Api\AuthController::class, 'sendEmailValid']);
            Route::post('/checkRegistrEmailValid', [App\Http\Controllers\Api\AuthController::class, 'checkRegistrEmailValid']);

            Route::post('/sendSmsValid', [App\Http\Controllers\Api\AuthController::class, 'sendSmsValid']);
            Route::post('/checkSmsValid', [App\Http\Controllers\Api\AuthController::class, 'checkSmsValid']);

            // 取得玩家資訊
            Route::get('/get_user_info/{uid}', [App\Http\Controllers\Api\AuthController::class, 'userInfo']);

            // 取得玩家surgame資訊
            Route::get('/get_user_surgame_info/{uid}', [App\Http\Controllers\Api\AuthController::class, 'userSurgameInfo']);

            // 刪除帳號
            Route::post('/delete_account', [App\Http\Controllers\Api\AuthController::class, 'deleteAccount']);
            // 網站會員登入
            Route::post('/web_firebase_login', [App\Http\Controllers\Api\AuthController::class, 'web_firebase_login']);
        });

        Route::group(['prefix' => 'map'], function () {
            // 玩家地圖列表
            Route::get('/list', [App\Http\Controllers\Api\MapController::class, 'list']);
            // 取得地圖資訊
            Route::get('/one/{id}', [App\Http\Controllers\Api\MapController::class, 'one']);

            // 已發布地圖列表
            Route::get('/publish_list', [App\Http\Controllers\Api\MapController::class, 'publish_list']);
            // 家園地圖列表
            Route::get('/home_list', [App\Http\Controllers\Api\MapController::class, 'home_list']);
            // 取得家園地圖
            Route::get('/homeByUid/{uid}', [App\Http\Controllers\Api\MapController::class, 'homeByUid']);

            // 草稿上傳地圖(用於第一次儲存)
            Route::post('/upload', [App\Http\Controllers\Api\MapController::class, 'upload']);
            // 複製草稿地圖
            Route::post('/copy_draft_map/{draft_id}', [App\Http\Controllers\Api\MapController::class, 'copyDraftMap']);
            // 編輯發布中地圖
            Route::post('/publish_update/{map_id}', [App\Http\Controllers\Api\MapController::class, 'editPublishedMap']);
            // 下架地圖
            Route::post('/unpublish/{map_id}', [App\Http\Controllers\Api\MapController::class, 'unpublishMap']);
            // 發布或更新地圖
            Route::post('/publish/{draft_id}', [App\Http\Controllers\Api\MapController::class, 'publishOrUpdate']);
            // 草稿更新或發布地圖
            Route::post('/update/{id}', [App\Http\Controllers\Api\MapController::class, 'update']);
            // 設定地圖照片
            Route::post('/update_photo/{map_id}', [App\Http\Controllers\Api\MapController::class, 'updateMapPhoto']);
            // 設定家園地圖(未啟用)
            // Route::post('/set_home_map/{map_id}', [App\Http\Controllers\Api\MapController::class, 'setHomeMap']);
            // 回收地圖
            Route::post('/recycle/{map_id}', [App\Http\Controllers\Api\MapController::class, 'recycleMap']);
            // 回收返回草稿
            Route::post('/recycle_to_draft/{map_id}', [App\Http\Controllers\Api\MapController::class, 'recycleToDraft']);
            // 刪除地圖
            Route::post('/delete/{id}', [App\Http\Controllers\Api\MapController::class, 'destroy']);

            // 取得所有地圖標籤
            Route::get('/tag_list', [App\Http\Controllers\Api\MapController::class, 'getAllMapTags']);

            // 地圖按讚, 收藏
            Route::post('/like/{map_id}', [App\Http\Controllers\Api\MapController::class, 'likeMap']);
            Route::post('/favorite/{map_id}', [App\Http\Controllers\Api\MapController::class, 'favoriteMap']);
        });

        Route::group(['prefix' => 'level'], function () {
            Route::get('/get_level/{level}/{sub_level}/{section}', [App\Http\Controllers\Api\LevelController::class, 'get_level']);
            Route::get('/one/{id}', [App\Http\Controllers\Api\LevelController::class, 'one']);

        });

        Route::group(['prefix' => 'equipment'], function () {
            Route::post('/update', [App\Http\Controllers\Api\EquipmentController::class, 'update']);
            Route::get('/one', [App\Http\Controllers\Api\EquipmentController::class, 'one']);
            Route::get('/oneByUid/{uid}', [App\Http\Controllers\Api\EquipmentController::class, 'oneByUid']);
        });
        Route::group(['prefix' => 'user'], function () {
            Route::get('/', [App\Http\Controllers\Api\UserController::class, 'index']);
            Route::get('/{id}', [App\Http\Controllers\Api\UserController::class, 'show']);
            Route::post('/', [App\Http\Controllers\Api\UserController::class, 'store']);
            Route::put('/{id}', [App\Http\Controllers\Api\UserController::class, 'update']);
            // Route::delete('/{id}', [App\Http\Controllers\Api\UserController::class, 'destroy']);

            Route::post('/delete_maps/{user_id}', [App\Http\Controllers\Api\UserController::class, 'delete_maps']);
            Route::post('/change_g8pad', [App\Http\Controllers\Api\UserController::class, 'change_g8pad']);
        });

        Route::group(['prefix' => 'user_item'], function () {
            Route::get('/get_avatars/{uid}', [App\Http\Controllers\Api\UserItemController::class, 'getAvatars']);
            Route::get('/get_maps/{uid}', [App\Http\Controllers\Api\UserItemController::class, 'getMaps']);

            Route::post('/give_item', [App\Http\Controllers\Api\UserItemController::class, 'giveItem']);

        });

        Route::group(['prefix' => 'user_mall_order'], function () {
            Route::post('/create', [App\Http\Controllers\Api\UserMallOrderController::class, 'create']);
        });

        Route::group(['prefix' => 'gacha_order'], function () {
            Route::post('/create', [App\Http\Controllers\Api\UserGachaOrderController::class, 'create']);

            Route::get('/user_log/{gacha_id}/{page}/{limit}', [App\Http\Controllers\Api\UserGachaOrderController::class, 'getLog']);
            Route::get('/user_times/{gacha_id}', [App\Http\Controllers\Api\UserGachaOrderController::class, 'getUserTimes']);
        });

        Route::group(['prefix' => 'item_price'], function () {
            Route::get('/', [App\Http\Controllers\Api\ItemPriceController::class, 'index']);

            Route::get('/item/{tag}/{item_id}', [App\Http\Controllers\Api\ItemPriceController::class, 'getByItemId']);
            Route::get('/manager/{tag}/{region}/{manager_id}', [App\Http\Controllers\Api\ItemPriceController::class, 'getByManagerId']);
            Route::get('/get_items', [App\Http\Controllers\Api\ItemPriceController::class, 'getItems']);
            Route::get('/get_cash_items', [App\Http\Controllers\Api\ItemPriceController::class, 'getCashItems']);
        });

        Route::group(['prefix' => 'item_price_upload'], function () {
            Route::get('/', [App\Http\Controllers\Api\ItemPriceUploadController::class, 'index']);
            Route::get('/{id}', [App\Http\Controllers\Api\ItemPriceUploadController::class, 'show']);
            Route::post('/', [App\Http\Controllers\Api\ItemPriceUploadController::class, 'store']);
            Route::put('/{id}', [App\Http\Controllers\Api\ItemPriceUploadController::class, 'update']);

            Route::post('/download_price', [App\Http\Controllers\Api\ItemPriceUploadController::class, 'downloadPrice']);
            Route::post('/new', [App\Http\Controllers\Api\ItemPriceUploadController::class, 'new']);
        });

        // 扭蛋
        Route::group(['prefix' => 'gacha'], function () {
            Route::get('/', [App\Http\Controllers\Api\GachaController::class, 'index']);
            Route::get('/{id}', [App\Http\Controllers\Api\GachaController::class, 'show']);
            Route::post('/', [App\Http\Controllers\Api\GachaController::class, 'store']);
            Route::put('/{id}', [App\Http\Controllers\Api\GachaController::class, 'update']);

            Route::get('/live/list', [App\Http\Controllers\Api\GachaController::class, 'liveList']);
        });

        // 扭蛋內容
        Route::group(['prefix' => 'gacha_items'], function () {
            Route::get('/', [App\Http\Controllers\Api\GachaItemController::class, 'index']);
            Route::get('/{item_id}', [App\Http\Controllers\Api\GachaItemController::class, 'show']);
            Route::post('/', [App\Http\Controllers\Api\GachaItemController::class, 'store']);
            Route::put('/{id}', [App\Http\Controllers\Api\GachaItemController::class, 'update']);
            Route::delete('/{id}', [App\Http\Controllers\Api\GachaItemController::class, 'destroy']);
        });

        Route::group(['prefix' => 'photon'], function () {
            Route::match(['get', 'post'], '/auth', [App\Http\Controllers\Api\PhotonController::class, 'auth']);
            Route::get('/user_profile/{uid}', [App\Http\Controllers\Api\PhotonController::class, 'userProfile']);
        });

        Route::group(['prefix' => 'data_center'], function () {
            Route::get('/get_item_list', [App\Http\Controllers\Api\DataCenterController::class, 'getItemList']);
            Route::get('/get_item/{item_id}', [App\Http\Controllers\Api\DataCenterController::class, 'getItem']);
        });

        // 任務系統
        Route::group(['prefix' => 'task'], function () {
            Route::get('/', [App\Http\Controllers\Api\TaskController::class, 'list']);
            Route::get('/categories', [App\Http\Controllers\Api\TaskController::class, 'categoryList']);
            Route::get('/current', [App\Http\Controllers\Api\TaskController::class, 'currentList']);
            Route::post('/assign', [App\Http\Controllers\Api\TaskController::class, 'assign']);
            Route::post('/progress', [App\Http\Controllers\Api\TaskController::class, 'progress']);
            Route::post('/reward', [App\Http\Controllers\Api\TaskController::class, 'reward']);
            Route::post('/cancle', [App\Http\Controllers\Api\TaskController::class, 'cancle']);
            // 一鍵領取所有任務獎勵
            Route::post('/claim_all_rewards', [App\Http\Controllers\Api\TaskController::class, 'claimAllRewards']);

            // 重置用戶任務
            Route::post('/reset', [App\Http\Controllers\Api\TaskController::class, 'reset']);
        });

        // 小遊戲
        Route::group(['prefix' => 'mini_game'], function () {
            Route::post('/create_record', [App\Http\Controllers\Api\MiniGameController::class, 'createRecord']);
            Route::get('/get_ranking/{game_id}', [App\Http\Controllers\Api\MiniGameController::class, 'getRanking']);
        });

        // 其他設定
        Route::group(['prefix' => 'setting'], function () {
            Route::get('/', [App\Http\Controllers\Api\SettingController::class, 'index']);
            Route::get('/{id}', [App\Http\Controllers\Api\SettingController::class, 'show']);
            Route::get('/byname/{name}', [App\Http\Controllers\Api\SettingController::class, 'showByName']);
            Route::post('/', [App\Http\Controllers\Api\SettingController::class, 'store']);
            Route::put('/{id}', [App\Http\Controllers\Api\SettingController::class, 'update']);
        });

        // 付款API
        Route::group(['prefix' => 'payment'], function () {
            // 建立訂單
            Route::post('/create_order', [App\Http\Controllers\Api\UserPayOrderController::class, 'createOrder']);
            // 驗證付款
            Route::post('/verify_purchase', [App\Http\Controllers\Api\UserPayOrderController::class, 'verifyPurchase']);

            // 藍新金流
            Route::post('/prepare-newebpay', [App\Http\Controllers\Api\NewEbpayController::class, 'preparePurchase']);
            Route::post('/check-newebpay', [App\Http\Controllers\Api\NewEbpayController::class, 'checkNewebPay']);
            Route::post('/notify-newebpay', [App\Http\Controllers\Api\NewEbpayController::class, 'notify'])->name('newebpay.notify');

            // 綠界金流
            Route::post('/prepare-ecpay', [App\Http\Controllers\Api\NewEcpayController::class, 'preparePurchase']);
            Route::post('/check-ecpay', [App\Http\Controllers\Api\NewEcpayController::class, 'checkEcpay']);
            Route::post('/notify-ecpay', [App\Http\Controllers\Api\NewEcpayController::class, 'notify'])->name('ecpay.notify');
        });

        // 好友系統
        Route::group(['prefix' => 'follows'], function () {
            Route::get('/', [App\Http\Controllers\Api\FollowController::class, 'getUserFollowers']);
            Route::get('/followings', [App\Http\Controllers\Api\FollowController::class, 'getUserFollowings']);

            Route::post('/', [App\Http\Controllers\Api\FollowController::class, 'follow']);
            Route::post('/unfollow', [App\Http\Controllers\Api\FollowController::class, 'unfollow']);
            Route::get('/check/{uid}', [App\Http\Controllers\Api\FollowController::class, 'isFollowing']);
            Route::post('/note/{follower_uid}', [App\Http\Controllers\Api\FollowController::class, 'updateNote']);

            // search
            Route::get('/search/{keyword}', [App\Http\Controllers\Api\FollowController::class, 'search']);
        });

        // 黑名單系統
        Route::group(['prefix' => 'blocklist'], function () {
            Route::get('/', [App\Http\Controllers\Api\BlocklistController::class, 'getBlockedUsers']);
            Route::post('/', [App\Http\Controllers\Api\BlocklistController::class, 'block']);
            Route::post('/unblock', [App\Http\Controllers\Api\BlocklistController::class, 'unblock']);
            Route::post('/check', [App\Http\Controllers\Api\BlocklistController::class, 'checkBlocked']);
        });

        // 信件系統
        Route::group(['prefix' => 'inbox'], function () {
            // 取得全部信件
            Route::get('/inbox_list', [App\Http\Controllers\Api\InboxController::class, 'inboxList']);
            Route::post('/read', [App\Http\Controllers\Api\InboxController::class, 'inboxRead']);
            Route::post('/claim_attachment', [App\Http\Controllers\Api\InboxController::class, 'inboxClaimAttachment']);
            Route::post('/delete', [App\Http\Controllers\Api\InboxController::class, 'inboxDelete']);
            Route::post('/reset', [App\Http\Controllers\Api\InboxController::class, 'reset_inbox']);
            Route::post('/claim_all', [App\Http\Controllers\Api\InboxController::class, 'inboxClaimAllAttachments']);

            // web-api
            Route::get('/', [App\Http\Controllers\Api\InboxController::class, 'index']);
            Route::post('/', [App\Http\Controllers\Api\InboxController::class, 'store']);
            Route::put('/{id}', [App\Http\Controllers\Api\InboxController::class, 'update']);
            Route::delete('/{id}', [App\Http\Controllers\Api\InboxController::class, 'destroy']);
            Route::get('/{id}', [App\Http\Controllers\Api\InboxController::class, 'show']);
        });

        // 材料關卡
        Route::group(['prefix' => 'material_stages'], function () {
            Route::get('/', [App\Http\Controllers\Api\MaterialStageController::class, 'lists']);
            Route::post('/enter_stage', [App\Http\Controllers\Api\MaterialStageController::class, 'enterMaterialStage']);
            // 隨機取得關卡獎勵
            Route::post('/get_rewards', [App\Http\Controllers\Api\MaterialStageController::class, 'getReward']);
            Route::post('/reset_status', [App\Http\Controllers\Api\MaterialStageController::class, 'resetStatus']);
            Route::post('/check_permission', [App\Http\Controllers\Api\MaterialStageController::class, 'checkPermission']);
        });

        // 玩家體力系統
        Route::prefix('stamina')->group(function () {
            Route::get('/current', [App\Http\Controllers\Api\StaminaController::class, 'getCurrentStamina']);
            // Route::post('/consume', [App\Http\Controllers\Api\StaminaController::class, 'consumeStamina']);
            Route::post('/purchase', [App\Http\Controllers\Api\StaminaController::class, 'purchaseStamina']);
        });

        // 寵物系統
        Route::group(['prefix' => 'user_pets'], function () {
            Route::get('/{uid}', [App\Http\Controllers\Api\UserPetController::class, 'getPets']);
            Route::post('/update', [App\Http\Controllers\Api\UserPetController::class, 'update']);
        });

        // 舉報系統
        Route::group(['prefix' => 'user_report'], function () {
            Route::post('/{reportedUid}', [App\Http\Controllers\Api\UserReportController::class, 'reportUser']);
        });

        // 統計資料
        Route::group(['prefix' => 'user_stats'], function () {
            Route::post('/', [App\Http\Controllers\Api\UserStatsController::class, 'updateUserStats']);
        });

        // 兌換碼系統
        Route::group(['prefix' => 'redeem'], function () {
            Route::get('/', [App\Http\Controllers\Api\RedeemController::class, 'getList']);
            Route::post('/', [App\Http\Controllers\Api\RedeemController::class, 'create']);
            Route::delete('/{id}', [App\Http\Controllers\Api\RedeemController::class, 'delete']);
            Route::post('/exchange', [App\Http\Controllers\Api\RedeemController::class, 'redeem']);
        });

        // 更新Gddata Items 資料
        Route::post('/refresh_gddata_items', [App\Http\Controllers\Api\AdminsController::class, 'refreshGddataItemsData']);

        // ================================= Surgame遊戲功能 =================================
        Route::group(['prefix' => 'character'], function () {
            // 使用者角色
            Route::group(['prefix' => 'user_character'], function () {
                // 取得角色
                Route::post('/obtain_character', [App\Http\Controllers\Api\CharacterController::class, 'obtainCharacter']);
                // 取得使用者的角色
                Route::get('/get_lists', [App\Http\Controllers\Api\CharacterController::class, 'getUserCharacterLists']);
                // 提升主角等級
                Route::post('/main_character_lv_up', [App\Http\Controllers\Api\CharacterController::class, 'mainCharacterLvUp']);
                // 重置人物等級
                Route::post('/reset_character_lv_up', [App\Http\Controllers\Api\CharacterController::class, 'resetCharacterLevel']);
            });

            // 冒險章節
            Route::group(['prefix' => 'journey'], function () {
                Route::get('/progress', [App\Http\Controllers\Api\CharacterJourneyController::class, 'progress']);
                Route::post('/update', [App\Http\Controllers\Api\CharacterJourneyController::class, 'update']);
                // 主線獎勵
                Route::post('/main_reward/claim', [App\Http\Controllers\Api\CharacterJourneyController::class, 'claimMainReward']);
                // 扣除體力
                Route::post('/deduct', [App\Http\Controllers\Api\CharacterJourneyController::class, 'deduct']);

                // 獎勵
                Route::get('/rewards_status', [App\Http\Controllers\Api\CharacterJourneyController::class, 'rewards']);
                Route::post('/reward/claim', [App\Http\Controllers\Api\CharacterJourneyController::class, 'claimReward']);

                // 重置章節進度
                Route::post('/reset_progress', [App\Http\Controllers\Api\CharacterJourneyController::class, 'resetProgress']);
            });
            // 星級挑戰
            Route::group(['prefix' => 'star_challenge'], function () {
                Route::get('/progress', [App\Http\Controllers\Api\CharacterStarChallengeController::class, 'progress']);
                Route::post('/update', [App\Http\Controllers\Api\CharacterStarChallengeController::class, 'update']);
                // 扣除體力
                Route::post('/deduct', [App\Http\Controllers\Api\CharacterStarChallengeController::class, 'deduct']);

                // 獎勵
                Route::get('/rewards_status', [App\Http\Controllers\Api\CharacterStarChallengeController::class, 'rewards']);
                Route::post('/reward/claim', [App\Http\Controllers\Api\CharacterStarChallengeController::class, 'claimReward']);

                // 重置挑戰進度
                Route::post('/reset_progress', [App\Http\Controllers\Api\CharacterStarChallengeController::class, 'resetProgress']);
            });
            // 陣位
            Route::group(['prefix' => 'deploy_slot'], function () {
                // 取得陣位資訊
                Route::get('/show_items/{uid?}', [App\Http\Controllers\Api\DeploySlotController::class, 'showItems']);
                Route::get('/show_equipments', [App\Http\Controllers\Api\DeploySlotController::class, 'showEquipments']);
                // 更新
                Route::post('/level_update', [App\Http\Controllers\Api\DeploySlotController::class, 'slotLvUpdate']);
                Route::post('/slot_update', [App\Http\Controllers\Api\DeploySlotController::class, 'slotUpdate']);

                // 裝備精煉&升級
                Route::post('/refine_level_update', [App\Http\Controllers\Api\DeploySlotController::class, 'updateRefineLv']);
                // 裝備強化
                Route::post('/enhance_level_update', [App\Http\Controllers\Api\DeploySlotController::class, 'updateEnhanceLv']);
            });
            // 星級
            Route::group(['prefix' => 'star_rank'], function () {
                // 星級提升
                Route::post('/level_update', [App\Http\Controllers\Api\CharacterController::class, 'startLevelUp']);
            });
        });

        // 巡邏
        Route::group(['prefix' => 'patrol'], function () {
            Route::post('/claim', [App\Http\Controllers\Api\PatrolController::class, 'claim']);
            Route::post('/quick_patrol', [App\Http\Controllers\Api\PatrolController::class, 'quickPatorl']);
        });

        // 角色軍階API
        Route::group(['prefix' => 'grade'], function () {
            // 取得玩家tasks
            Route::get('/user_tasks', [App\Http\Controllers\Api\UserGradeController::class, 'getUserGradeTask']);
            // 更新任務進度
            Route::post('/update_progress', [App\Http\Controllers\Api\UserGradeController::class, 'updateProgress']);
            // 領取獎勵
            Route::post('/claim_reward', [App\Http\Controllers\Api\UserGradeController::class, 'claminGradeReward']);
        });

        // 角色背包
        Route::group(['prefix' => 'inventory'], function () {
            // 查詢所有道具
            Route::get('/items', [App\Http\Controllers\Api\PackageController::class, 'getAllItems']);
            // 查詢特定道具
            Route::post('/items', [App\Http\Controllers\Api\PackageController::class, 'getSpecificItems']);
            // 使用背包物品
            Route::post('/use_item', [App\Http\Controllers\Api\PackageController::class, 'useItem']);
        });

        // 人物裝備
        Route::group(['prefix' => 'surgame_equipment'], function () {
            // 取得當前所有裝備
            Route::get('/get_items', [App\Http\Controllers\Api\SurgameEquipmentController::class, 'getCurrentEquipments']);
            // 獲得裝備
            Route::post('/obtain', [App\Http\Controllers\Api\SurgameEquipmentController::class, 'obtainEquipment']);
            // 使用裝備
            Route::post('/use', [App\Http\Controllers\Api\SurgameEquipmentController::class, 'useEquipment']);
            // 裝備分解
            Route::post('/salvage', [App\Http\Controllers\Api\SurgameEquipmentController::class, 'salvageEquipment']);
            // 指定陣位一件換裝
            Route::post('/auto_equip', [App\Http\Controllers\Api\SurgameEquipmentController::class, 'autoEquip']);
        });

        // 玩家天賦系統
        Route::group(['prefix' => 'talent'], function () {
            // 玩家天賦
            Route::get('/user_talents', [App\Http\Controllers\Api\TalentController::class, 'getUserTalents']);
            // 抽取天賦
            Route::post('/draw', [App\Http\Controllers\Api\TalentController::class, 'drawTalent']);
            // 建立天賦獎池
            Route::post('/create_pool', [App\Http\Controllers\Api\TalentController::class, 'createTalentPool']);
        });

        // 寶物系統
        Route::group(['prefix' => 'treasure'], function () {
            Route::get('/', [App\Http\Controllers\Api\TreasureController::class, 'test']);
            Route::post('/obtain',[App\Http\Controllers\Api\TreasureController::class, 'obtain']);
            Route::post('/fuse',[App\Http\Controllers\Api\TreasureController::class, 'fuse']);
            Route::post('/auto_fuse',[App\Http\Controllers\Api\TreasureController::class, 'autoFuse']);
            Route::post('/reset',[App\Http\Controllers\Api\TreasureController::class, 'reset']);
        });

    });
});

// Route::post('/tokens/create', function (Request $request) {
//     $token = $request->user()->createToken($request->token_name);

//     return ['token' => $token->plainTextToken];
// });

// Route::post('/tokens/create',[App\Http\Controllers\Api\TokensController::class,'create']);

// Route::middleware('auth:sanctum')->group(function () {
// Route::apiResource('admins', 'App\Http\Controllers\Api\AdminsController');
// });
