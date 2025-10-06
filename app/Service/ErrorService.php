<?php

namespace App\Service;

class ErrorService extends AppService
{
    public static function returnData($from, $error, $message, $field)
    {
        return [
            'from' => $from,
            'error' => $error,
            'message' => $message,
            'field' => $field,
        ];
    }

    public static function errorCode($from, $error)
    {
        switch ($error) {
            case 'SYSTEM:0001':
                return self::returnData($from, $error, __('資料擁有者錯誤'), 'user_diff');
                break;
            case 'SYSTEM:0002':
                return self::returnData($from, $error, __('查無資料'), 'not_find');
                break;
            case 'SYSTEM:0003':
                return self::returnData($from, $error, __('儲存失敗錯誤'), 'save');
                break;
            case 'SYSTEM:0004':
                return self::returnData($from, $error, __('資料刪除失敗'), 'destroy');
                break;

            case 'AUTH:0001':
                return self::returnData($from, $error, __('帳號錯誤'), 'account');
                break;
            case 'AUTH:0002':
                return self::returnData($from, $error, __('密碼錯誤'), 'password');
                break;
            case 'AUTH:0003':
                return self::returnData($from, $error, __('帳號異常錯誤'), 'is_active');
                break;
            case 'AUTH:0004':
                return self::returnData($from, $error, __('帳號已存在'), 'account_exist');
                break;
            case 'AUTH:0005':
                return self::returnData($from, $error, __('uid錯誤'), 'uid');
                break;
            case 'AUTH:0006':
                return self::returnData($from, $error, __('用戶不存在'), 'user');
                break;
            case 'AUTH:0007':
                return self::returnData($from, $error, __('MAC ID錯誤'), 'mac_id');
                break;
            case 'AUTH:0008':
                return self::returnData($from, $error, __('Email 錯誤'), 'email');
                break;
            case 'AUTH:0009':
                return self::returnData($from, $error, __('firebase_uid 錯誤'), 'firebase_uid');
                break;
            case 'AUTH:0010':
                return self::returnData($from, $error, __('firebase_name 錯誤'), 'firebase_name');
                break;
            case 'AUTH:0011':
                return self::returnData($from, $error, __('firebase_providerId 錯誤'), 'firebase_providerId');
                break;
            case 'AUTH:0012':
                return self::returnData($from, $error, __('firebase_accessToken 錯誤'), 'firebase_accessToken');
                break;
            case 'AUTH:0013':
                return self::returnData($from, $error, __('姓名不能為空'), 'name_null');
                break;
            case 'AUTH:0014':
                return self::returnData($from, $error, __('姓名已存在'), 'name_exist');
                break;
            case 'AUTH:0015':
                return self::returnData($from, $error, __('改名卡或商城幣數量不足'), 'change_name_cnt_not_enough');
                break;

            case 'MAP:0001':
                return self::returnData($from, $error, __('地圖資料錯誤'), 'map_data');
                break;
            case 'MAP:0002':
                return self::returnData($from, $error, __('地圖數量超過上限'), 'map_limit');
                break;
            case 'MAP:0003':
                return self::returnData($from, $error, __('取得地圖錯誤'), 'get_user_map');
                break;
            case 'MAP:0004':
                return self::returnData($from, $error, __('地圖資料轉換檔案失敗'), 'map_data_to_file');
                break;
            case 'MAP:0005':
                return self::returnData($from, $error, __('不可以刪除最後一張地圖'), 'map_delete');
                break;
            case 'MAP:0006':
                return self::returnData($from, $error, __('不可以刪除家園'), 'map_delete_home');
                break;
            case 'MAP:0007':
                return self::returnData($from, $error, __('家園地圖已存在'), 'map_home_exist');
                break;

            case 'LEVEL:0001':
                return self::returnData($from, $error, __('玩家主線關卡資料錯誤'), 'level_data');
                break;
            case 'LEVEL:0002':
                return self::returnData($from, $error, __('主線關卡資料不足'), 'level__sub_level__section');
                break;

            case 'MallOrder:0001':
                return self::returnData($from, $error, __('訂單價錢錯誤'), 'total_price');
                break;
            case 'MallOrder:0002':
                return self::returnData($from, $error, __('幣別錯誤'), 'currency_item_id');
                break;
            case 'MallOrder:0003':
                return self::returnData($from, $error, __('商品錯誤'), 'order_item');
                break;
            case 'MallOrder:0004':
                return self::returnData($from, $error, __('商品item_id錯誤'), 'item_id');
                break;
            case 'MallOrder:0005':
                return self::returnData($from, $error, __('商品region錯誤'), 'region');
                break;
            case 'MallOrder:0006':
                return self::returnData($from, $error, __('商品category錯誤'), 'category');
                break;
            case 'MallOrder:0007':
                return self::returnData($from, $error, __('商品type錯誤'), 'type');
                break;
            case 'MallOrder:0008':
                return self::returnData($from, $error, __('商品qty錯誤'), 'qty');
                break;
            case 'MallOrder:0009':
                return self::returnData($from, $error, __('商品資料比對錯誤'), 'itemdata mapping error');
                break;
            case 'MallOrder:0010':
                return self::returnData($from, $error, __('貨幣不足'), 'currency_item_low');
                break;
            case 'MallOrder:0011':
                return self::returnData($from, $error, __('道具價格比對錯誤'), 'item_price');
                break;
            case 'MallOrder:0012':
                return self::returnData($from, $error, __('重複取得道具'), 'item_repeat');
                break;
            case 'MallOrder:0013':
                return self::returnData($from, $error, __('商品Tag錯誤'), 'tag');
                break;
            case 'MallOrder:0014':
                return self::returnData($from, $error, __('找不到道具'), 'can_not_find_item');
                break;

            case 'GachaOrder:0001':
                return self::returnData($from, $error, __('扭蛋ID錯誤'), 'gacha_id');
                break;
            case 'GachaOrder:0002':
                return self::returnData($from, $error, __('扭蛋資料錯誤'), 'gacha');
                break;
            case 'GachaOrder:0003':
                return self::returnData($from, $error, __('扭蛋次數錯誤'), 'times');
                break;
            case 'GachaOrder:0004':
                return self::returnData($from, $error, __('目前不在活動期間'), 'timestamp');
                break;
            case 'GachaOrder:0005':
                return self::returnData($from, $error, __('抽取錯誤'), 'drawGacha null');
                break;
            case 'GachaOrder:0006':
                return self::returnData($from, $error, __('扭蛋item資料錯誤'), 'item_data');
                break;

            case 'UserItem:0001':
                return self::returnData($from, $error, __('批次發放錯誤'), 'additems');
                break;
            case 'UserItem:0002':
                return self::returnData($from, $error, __('道具領取失敗'), 'reward_claim_failed');
                break;
            case 'UserItem:0003':
                return self::returnData($from, $error, __('道具不足'), 'item_not_enough');
                break;
            case 'UserItem:0004':
                return self::returnData($from, $error, __('額外道具不足'), 'extra_item_not_enough');
                break;
            case 'UserItem:0005':
                return self::returnData($from, $error, __('使用者無相關道具'), 'item_info_not_enough');
                break;

                // 付款錯誤資訊
            case 'UserPayOrder:0001':
                return self::returnData($from, $error, __('訂單已經處理過!'), 'order_id');
                break;
            case 'UserPayOrder:0002':
                return self::returnData($from, $error, __('訂單不存在!'), 'order_id');
                break;
            case 'UserPayOrder:0003':
                return self::returnData($from, $error, __('購買失敗!'), 'order_id');
                break;
            case 'UserPayOrder:0004':
                return self::returnData($from, $error, __('apple收據格式錯誤'), 'receipt');
                break;
            case 'UserPayOrder:0005':
                return self::returnData($from, $error, __('google收據格式錯誤'), 'purchase_token');
                break;

                // 任務系統錯誤資訊
            case 'TASK:0001':
                return self::returnData($from, $error, __('任務不存在'), 'task_not_found');
                break;
            case 'TASK:0002':
                return self::returnData($from, $error, __('任務條件未達成'), 'task_condition_not_met');
                break;
            case 'TASK:0003':
                return self::returnData($from, $error, __('任務尚未完成'), 'task_not_completed');
                break;
            case 'TASK:0004':
                return self::returnData($from, $error, __('任務已領取'), 'task_claimed');
                break;
            case 'TASK:0005':
                return self::returnData($from, $error, __('任務獎勵已領取'), 'task_reward_claimed');
                break;
            case 'TASK:0006':
                return self::returnData($from, $error, __('前置任務未完成'), 'task_pre_not_completed');
                break;
            case 'TASK:0007':
                return self::returnData($from, $error, __('任務尚未重置'), 'task_not_reset');
                break;
            case 'TASK:0008':
                return self::returnData($from, $error, __('任務已完成'), 'task_completed');
                break;
            case 'TASK:0009':
                return self::returnData($from, $error, __('任務類型錯誤'), 'task_type');
                break;

                // 追蹤錯誤資訊
            case 'FOLLOW:0001':
                return self::returnData($from, $error, __('不能追蹤自己'), 'follow_self');
                break;
            case 'FOLLOW:0002':
                return self::returnData($from, $error, __('追蹤失敗'), 'follow_failed');
                break;
            case 'FOLLOW:0003':
                return self::returnData($from, $error, __('取消追蹤失敗'), 'unfollow_failed');
                break;
            case 'FOLLOW:0004':
                return self::returnData($from, $error, __('被追蹤者不存在'), 'following_not_found');
                break;
            case 'FOLLOW:0005':
                return self::returnData($from, $error, __('備註更新失敗'), 'note_update_failed');
                break;
            case 'FOLLOW:0006':
                return self::returnData($from, $error, __('你已封鎖該用戶，請先解除封鎖'), 'blocked_user');
                break;
            case 'FOLLOW:0007':
                return self::returnData($from, $error, __('好友搜尋字數限制為2~8個字'), 'search_length');
                break;

                // 黑名單功能
            case 'BLOCK:0001':
                return self::returnData($from, $error, __('無法封鎖自己'), 'block_self');
                break;
            case 'BLOCK:0002':
                return self::returnData($from, $error, __('取得封鎖我的人失敗'), 'get_blocked_by_failed');
                break;
            case 'BLOCK:0003':
                return self::returnData($from, $error, __('封鎖使用者失敗'), 'block_user_failed');
                break;
            case 'BLOCK:0004':
                return self::returnData($from, $error, __('解除封鎖失敗'), 'unblock_failed');
                break;
            case 'BLOCK:0005':
                return self::returnData($from, $error, __('封鎖使用者不存在'), 'block_user_not_found');
                break;
            case 'BLOCK:0006':
                return self::returnData($from, $error, __('封鎖使用者已存在'), 'block_user_already_exists');
                break;
                // 你被對方封鎖
            case 'BLOCK:0007':
                return self::returnData($from, $error, __('對方已封鎖你'), 'blocked_by_user');
                break;

                // 信件系統錯誤資訊
            case 'INBOX:0001':
                return self::returnData($from, $error, __('信件不存在'), 'inbox_not_found');
                break;
            case 'INBOX:0002':
                return self::returnData($from, $error, __('信件已過期'), 'inbox_expired');
                break;
            case 'INBOX:0003':
                return self::returnData($from, $error, __('信件已取消'), 'inbox_cancelled');
                break;
            case 'INBOX:0004':
                return self::returnData($from, $error, __('信件已讀取'), 'inbox_read');
                break;
            case 'INBOX:0005':
                return self::returnData($from, $error, __('附件不存在'), 'inbox_attachment_not_found');
                break;
            case 'INBOX:0006':
                return self::returnData($from, $error, __('附件已領取'), 'inbox_attachment_claimed');
                break;
            case 'INBOX:0007':
                return self::returnData($from, $error, __('附件尚未領取'), 'inbox_attachment_not_claimed');
                break;
            case 'INBOX:0008':
                return self::returnData($from, $error, __('信件刪除失敗'), 'inbox_delete_failed');
                break;

                // 寵物系統錯誤資訊
            case 'PET:0001':
                return self::returnData($from, $error, __('寵物不存在'), 'pet_not_found');
                break;
            case 'PET:0002':
                return self::returnData($from, $error, __('寵物id 輸入錯誤'), 'pet_id_error');
                break;
            case 'PET:0003':
                return self::returnData($from, $error, __('寵物更新失敗'), 'pet_update_failed');
                break;

                // 體力系統錯誤資訊
            case 'STAMINA:0001':
                return self::returnData($from, $error, __('體力不足'), 'stamina_not_enough');
                break;
            case 'STAMINA:0002':
                return self::returnData($from, $error, __('體力扣除失敗'), 'stamina_change_failed');
                break;
            case 'STAMINA:0003':
                return self::returnData($from, $error, __('體力道具轉換失敗'), 'stamina_convert_failed');
                break;

                // 材料關卡錯誤資訊
            case 'STAGE:0001':
                return self::returnData($from, $error, __('關卡不存在'), 'stage_not_found');
                break;
            case 'STAGE:0002':
                return self::returnData($from, $error, __('發放道具失敗'), 'item_add_failed');
                break;
            case 'STAGE:0003':
                return self::returnData($from, $error, __('掃蕩次數超過上限'), 'sweep_max_exceeded');
                break;
            case 'STAGE:0004':
                return self::returnData($from, $error, __('掃蕩次數不足'), 'sweep_count_not_enough');
                break;
            case 'STAGE:0005':
                return self::returnData($from, $error, __('尚未通過關卡無法掃蕩'), 'cant_sweep');
                break;

                // 陣位相關錯誤
            case 'DeploySlot:0001':
                return self::returnData($from, $error, __('欄位型態錯誤'), 'column_type_error');
                break;
            case 'DeploySlot:0002':
                return self::returnData($from, $error, __('一次只能更新一個欄位'), 'too_many_columns');
                break;
            case 'DeploySlot:0003':
                return self::returnData($from, $error, __('陣位索引錯誤'), 'slot_index_error');
                break;
            case 'DeploySlot:0004':
                return self::returnData($from, $error, __('陣位資料格式錯誤'), 'slot_data_format_error');
                break;
            case 'DeploySlot:0005':
                return self::returnData($from, $error, __('角色不可重複上陣'), 'duplicate_character');
                break;
            case 'DeploySlot:0006':
                return self::returnData($from, $error, __('陣位升級材料不足'), 'character_not_owned');
                break;
            case 'DeploySlot:0007':
                return self::returnData($from, $error, __('陣位等級相差不能超過10等'), 'level_gap_exceeded');
                break;
            case 'DeploySlot:0008':
                return self::returnData($from, $error, __('陣位等級不能超過上限'), 'slot_level_max');
                break;

                // 角色相關
            case 'CHARACTER:0001':
                return self::returnData($from, $error, __('尚未擁有或無此角色'), 'find_character_fail');
                break;

                // 巡邏相關
            case 'PATROL:0001':
                return self::returnData($from, $error, __('巡邏時間未滿，無法領取獎勵。'), 'claim_patrol_time_short');
                break;
            case 'PATROL:0002':
                return self::returnData($from, $error, __('尚未滿足領取條件或無法領取巡邏獎勵'), 'claim_patrol_fail');
                break;

                // 角色升級相關
            case 'PlayerLevelUp:0001':
                return self::returnData($from, $error, __('主角升級資訊不存在'), 'player_level_up_not_found');
                break;
            case 'PlayerLevelUp:0002':
                return self::returnData($from, $error, __('角色升級失敗，已達上限或經驗值不足'), 'player_level_up_failed');
                break;
            case 'PlayerLevelUp:0003':
                return self::returnData($from, $error, __('主角升級失敗，請檢查相關資料'), 'main_character_level_up_failed');
                break;

                // 角色星級相關
            case 'CharacterRank:0001':
                return self::returnData($from, $error, __('角色已達最高星級'), 'character_max_star_level');
                break;
            case 'CharacterRank:0002':
                return self::returnData($from, $error, __('角色星級資料錯誤'), 'character_star_data_error');
                break;
            case 'CharacterRank:0003':
                return self::returnData($from, $error, __('角色星級材料不足'), 'character_star_material_not_enough');
                break;

                // 冒險章節相關
            case 'JOURNEY:0001':
                return self::returnData($from, $error, __('章節參數錯誤'), 'journey_chapter');
                break;
            case 'JOURNEY:0002':
                return self::returnData($from, $error, __('波次參數錯誤'), 'journey_wave');
                break;
            case 'JOURNEY:0003':
                return self::returnData($from, $error, __('無效的獎勵資訊'), 'journey_reward');
                break;
            case 'JOURNEY:0004':
                return self::returnData($from, $error, __('尚有前置獎勵未領取，請依序領取。'), 'journey_chapter_not_found');
                break;

            case 'JourneyReward:0001':
                return self::returnData($from, $error, __('章節獎勵不存在'), 'journey_reward_not_found');
                break;
            case 'JourneyReward:0002':
                return self::returnData($from, $error, __('章節獎勵條件不足'), 'journey_reward_unmet');
                break;
            case 'JourneyReward:0003':
                return self::returnData($from, $error, __('尚未建立章節進度'), 'journey_reward_progress_missing');
                break;
            case 'JourneyReward:0004':
                return self::returnData($from, $error, __('章節獎勵已領取'), 'journey_reward_claimed');
                break;
            case 'JourneyReward:0005':
                return self::returnData($from, $error, __('章節獎勵參數錯誤'), 'journey_reward_param');
                break;

                // 星級挑戰相關
            case 'StarChallenge:0001':
                return self::returnData($from, $error, __('星級資料格式錯誤'), 'star_challenge_payload');
                break;
            case 'StarChallenge:0002':
                return self::returnData($from, $error, __('指定章節不存在'), 'star_challenge_chapter');
                break;
            case 'StarReward:0001':
                return self::returnData($from, $error, __('星級獎勵不存在'), 'star_reward_not_found');
                break;
            case 'StarReward:0002':
                return self::returnData($from, $error, __('星數不足，無法領取獎勵'), 'star_reward_not_enough');
                break;
            case 'StarReward:0003':
                return self::returnData($from, $error, __('星級獎勵已領取'), 'star_reward_claimed');
                break;
            case 'StarReward:0005':
                return self::returnData($from, $error, __('星級獎勵參數錯誤'), 'star_reward_param');
                break;

                // 角色軍階相關
            case 'GRADE:0001':
                return self::returnData($from, $error, __('軍階資料錯誤'), 'character_grade_data_error');
            case 'GRADE:0002':
                return self::returnData($from, $error, __('查無相關使用者軍階資料'), 'character_grade_not_found');
            case 'GRADE:0003':
                return self::returnData($from, $error, __('軍階升級失敗，請檢查相關資料'), 'character_grade_upgrade_failed');
            case 'GRADE:0004':
                return self::returnData($from, $error, __('軍階任務接取失敗'), 'character_grade_mission_failed');

                // 角色背包功能
            case 'INVENTORY:0001':
                return self::returnData($from, $error, __('背包資料錯誤'), 'inventory_data_error');
                break;

            case 'INVENTORY:0002':
                return self::returnData($from, $error, __('查無相關背包資料'), 'inventory_not_found');
                break;

            case 'INVENTORY:0003':
                return self::returnData($from, $error, __('道具不存在於背包內'), 'item_not_in_inventory');
                break;

            case 'INVENTORY:0004':
                return self::returnData($from, $error, __('道具數量不足，無法使用或移除'), 'item_quantity_not_enough');
                break;

            case 'INVENTORY:0005':
                return self::returnData($from, $error, __('道具使用失敗，請檢查條件是否符合'), 'item_use_failed');
                break;

            case 'INVENTORY:0006':
                return self::returnData($from, $error, __('背包更新失敗，請稍後再試'), 'inventory_update_failed');
                break;
            case 'INVENTORY:0007':
                return self::returnData($from, $error, __('使用道具數量與內容物資料數量不相符'), 'item_amount_mismatch');
                break;

                // 維護系統錯誤資訊
            case 'MAINTENANCE:0001':
                return self::returnData($from, $error, __('資料取得錯誤'), 'maintenance_data_error');
                break;

                // 裝備系統錯誤資訊
            case 'EQUIPMENT:0001':
                return self::returnData($from, $error, __('裝備不存在'), 'equipment_not_found');
                break;
            case 'EQUIPMENT:0002':
                return self::returnData($from, $error, __('裝備缺少指定資料或資料不正確'), 'equipment_bind_data_error');
                break;
            case 'EQUIPMENT:0003':
                return self::returnData($from, $error, __('裝備取得失敗'), 'equipment_acquire_failed');
                break;
            case 'EQUIPMENT:0004':
                return self::returnData($from, $error, __('裝備綁定失敗'), 'equipment_bind_failed');
                break;
            case 'EQUIPMENT:0005':
                return self::returnData($from, $error, __('該道具不屬於裝備'), 'not_equipment_item');
                break;
            case 'EQUIPMENT:0006':
                return self::returnData($from, $error, __('裝備分解失敗'), 'equipment_dismantle_failed');
                break;
            case 'EQUIPMENT:0007':
                return self::returnData($from, $error, __('所選裝備不屬於該用戶'), 'equipment_not_user');
                break;
            case 'EQUIPMENT:0008':
                return self::returnData($from, $error, __('裝備精煉材料不足'), 'equipment_refine_material_not_enough');
                break;
            case 'EQUIPMENT:0009':
                return self::returnData($from, $error, __('裝備精煉失敗'), 'equipment_refine_failed');
                break;
            case 'EQUIPMENT:0010':
                return self::returnData($from, $error, __('精煉裝備已達最高級'), 'equipment_refine_max_level');
                break;
            case 'EQUIPMENT:0011':
                return self::returnData($from, $error, __('裝備強化材料不足'), 'equipment_enhance_material_not_enough');
                break;
            case 'EQUIPMENT:0012':
                return self::returnData($from, $error, __('裝備強化失敗'), 'equipment_enhance_failed');
                break;

            // 寶物系統錯誤資訊
            case 'TREASURE:0001':
                return self::returnData($from, $error, __('寶物不存在'), 'treasure_not_found');
                break;
            case 'TREASURE:0002':
                return self::returnData($from, $error, __('寶物抽取失敗'), 'treasure_draw_failed');
                break;
            case 'TREASURE:0003':
                return self::returnData($from, $error, __('寶物抽取次數不足'), 'treasure_draw_not_enough');
                break;
            case 'TREASURE:0004':
                return self::returnData($from, $error, __('寶物合成缺少材料或資料不正確'), 'treasure_combine_data_error');
                break;
            case 'TREASURE:0005':
                return self::returnData($from, $error, __('寶物合成失敗'), 'treasure_combine_failed');
                break;
            case 'TREASURE:0006':
                return self::returnData($from, $error, __('一鍵合成失敗'), 'treasure_combine_all_failed');
                break;
            case 'TREASURE:0007':
                return self::returnData($from, $error, __('寶物退回失敗'), 'treasure_return_failed');
                break;
            case 'TREASURE:0008':
                return self::returnData($from, $error, __('所選寶物不屬於該用戶'), 'treasure_not_user');
                break;
            case 'TREASURE:0009':
                return self::returnData($from, $error, __('寶物數量不足'), 'treasure_not_enough');
                break;
            case 'TREASURE:0010':
                return self::returnData($from, $error, __('該寶物不可合成'), 'treasure_not_combinable');
                break;
            case 'TREASURE:0011':
                return self::returnData($from, $error, __('該寶物不可退回'), 'treasure_not_returnable');
                break;
            case 'TREASURE:0012':
                return self::returnData($from, $error, __('寶物狀態異常，無法操作'), 'treasure_invalid_status');
                break;
            case 'TREASURE:0013':
                return self::returnData($from, $error, __('該材料不可用於此次合成'), 'treasure_material_not_usable');
            case 'TREASURE:0014':
                return self::returnData($from, $error, __('寶物等級已達最高級'), 'treasure_max_level');
                break;
            case 'TREASURE:0015':
                return self::returnData($from, $error, __('寶物合成數量錯誤'), 'treasure_combine_count_invalid');


                // 天賦系統錯誤資訊
            case 'TALENT:0001':
                return self::returnData($from, $error, __('抽取天賦失敗，請確認是否有可用的天賦獎池'), 'draw_talent_failed');
                break;
            case 'TALENT:0002':
                return self::returnData($from, $error, __('天賦獎池不存在'), 'talent_pool_not_found');
                break;
            case 'TALENT:0003':
                return self::returnData($from, $error, __('天賦獎池已達最高等級，無法繼續抽取'), 'talent_pool_max_level');
                break;
            case 'TALENT:0004':
                return self::returnData($from, $error, __('天賦等級不符合要求'), 'talent_level_requirement');
                break;
            case 'TALENT:0005':
                return self::returnData($from, $error, __('天賦抽取記錄不存在'), 'talent_log_not_found');
                break;
            case 'TALENT:0006':
                return self::returnData($from, $error, __('天賦獎勵發放失敗'), 'talent_reward_failed');
                break;
            case 'TALENT:0007':
                return self::returnData($from, $error, __('天賦抽取失敗，請檢查條件是否符合'), 'talent_draw_failed');
                break;

            default:
                return self::returnData($from, $error, __('不明錯誤'.$from.$error), 'other');
                break;
        }

    }
}
