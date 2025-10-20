<?php

namespace App\Service;

use App\Models\GddbSurgameHeroes as Heros;
use App\Models\GddbSurgamePlayerLvUp as PlayerLvUp;
use App\Models\UserCharacter;
use App\Models\UserItems;
use App\Models\UserSurGameInfo as UserInfo;

class CharacterService
{
    public $weightLists = [
        'R' => 7,
        'SR' => 2,
        'SSR' => 1,
    ];

    private const LEVEL_UP_ITEM_ID = 190; // 假設這是主角升級所需的道具ID

    public function obtainCharacter()
    {
        // 取出英雄資料（id、unique_id、rarity）
        $heros = Heros::where('unique_id', '>', 1000)
            ->get(['id', 'unique_id', 'rarity']);

        foreach ($heros as $hero) {
            $hero->weight = $this->weightLists[$hero->rarity] ?? 0;
        }

        // 計算總權重
        $totalWeight = $heros->sum('weight');

        if ($totalWeight <= 0) {
            return null;
        }

        // 抽一個隨機數
        $rand = rand(1, $totalWeight);

        // 選出角色
        $current = 0;
        foreach ($heros as $hero) {
            $current += $hero->weight;
            if ($rand <= $current) {
                return $hero;
            }
        }

        return null;
    }

    // 主角等級同步
    public static function syncMainCharacter($user)
    {
        $currentUser = UserInfo::where('uid', $user->uid)->first();
        if (empty($currentUser)) {
            return [
                'success' => false,
                'error_code' => 'AUTH:0001',
            ];
        }

        $playerLvUp = PlayerLvUp::where('account_lv', $currentUser->main_character_level + 1)->first();
        if (empty($playerLvUp)) {
            return [
                'success' => false,
                'error_code' => 'PlayerLevelUp:0001',
            ];
        }

        // 1. 檢查主角身上道具
        $itemCheck = self::canUpgrade($user);
        if ($itemCheck['success'] == 0) {
            return [
                'success' => false,
                'error_code' => $itemCheck['error_code'],
            ];
        }
        // 2. 進行角色升級
        $currentUserInfo = UserInfo::with('user')->where('uid', $currentUser->uid)->first();
        if (! $currentUserInfo) {
            return [
                'success' => false,
                'error_code' => 'AUTH:0001',
            ];
        }
        $currentUserInfo->main_character_level += 1;
        $currentUserInfo->save();
        // 取得升級獎勵
        $reward = $playerLvUp->reward ?? [];
        if (empty($reward)) {
            return [
                'success' => true,
                'message' => '升級成功，無獎勵',
            ];
        }

        // 獎勵為[item_id, amount]格式
        if (is_string($reward)) {
            $reward = json_decode($reward, true);
        }

        // 檢查獎勵是否為陣列
        if (! is_array($reward)) {
            $reward = [];
        }

        if (is_array($reward) && count($reward) == 2) {
            $reward = [
                'item_id' => $reward[0],
                'amount' => $reward[1],
            ];
        }
        if (! empty($reward)) {
            $result = UserItemService::addItem(50, $currentUserInfo->user->id, $currentUserInfo->uid, $reward['item_id'], $reward['amount'], 1, '主角升級獎勵');
            if ($result['success'] == 0) {
                return [
                    'success' => false,
                    'error_code' => $result['error_code'],
                ];
            }
        }

        return [
            'success' => true,
            'reward' => $reward,
        ];
    }

    // 檢查是否能夠升級
    public static function canUpgrade($user)
    {
        $item = UserItems::where('user_id', $user->id)->where('item_id', self::LEVEL_UP_ITEM_ID)->first();
        if (empty($item)) {
            $addNewItem = UserItemService::addItem(
                1,
                $user->id,
                $user->uid,
                self::LEVEL_UP_ITEM_ID,
                100,
                1,
                '初始升級道具');
            if ($addNewItem['success'] == 0) {
                return ['success' => 0, 'error_code' => $addNewItem['error_code']];
            }
            $item = UserItems::where('user_id', $user->id)->where('item_id', self::LEVEL_UP_ITEM_ID)->first();

        }
        $nextLevel = $user?->surgameUserInfo?->main_character_level + 1 ?? null;
        if (empty($nextLevel)) {
            return ['success' => 0, 'error_code' => 'PlayerLevelUp:0003'];
        }

        $required_amount = PlayerLvUp::where('account_lv', $nextLevel)->value('xp');
        if (empty($required_amount)) {
            return ['success' => 0, 'error_code' => 'PlayerLevelUp:0001'];
        }
        // 檢查道具數量是否足夠
        if (empty($item) || $item->qty < $required_amount) {
            return ['success' => 0, 'error_code' => 'UserItem:0003'];
        }

        return ['success' => 1, 'error_code' => ''];
    }

    // 取得使用者角色列表
    public static function getUserCharacterList($uid)
    {
        $deployCharAry = UserCharacter::where('uid', $uid)
            ->where('has_use', 1)
            ->orderByRaw('CAST(slot_index AS UNSIGNED)')
            ->get(['character_id', 'star_level', 'slot_index'])
            ->map(fn ($c) => [
                'slot_index' => (int) $c->slot_index,
                'character_id' => (int) $c->character_id,
                'star_level' => (int) $c->star_level,
            ])
            ->toArray();

        $undeployCharAry = UserCharacter::where('uid', $uid)
            ->where('has_use', 0)
            ->join('gddb_surgame_heroes', 'user_characters.character_id', '=', 'gddb_surgame_heroes.unique_id')
            ->get(['user_characters.character_id', 'user_characters.star_level', 'gddb_surgame_heroes.rarity'])
            ->sort(function ($a, $b) {
                // 定義稀有度排序 SSR > SR > R
                $rarityOrder = ['SSR' => 3, 'SR' => 2, 'R' => 1];
                $rarityA = $rarityOrder[$a->rarity] ?? 0;
                $rarityB = $rarityOrder[$b->rarity] ?? 0;
                if ($rarityA === $rarityB) {
                    // 稀有度相同時，依 star_level 排序（高到低）
                    return $b->star_level <=> $a->star_level;
                }

                // 稀有度排序（高到低）
                return $rarityB <=> $rarityA;
            })
            ->map(fn ($c) => [
                'character_id' => $c->character_id,
                'star_level' => $c->star_level,
                'rarity' => $c->rarity,
            ])
            ->values()
            ->toArray();

        return [
            'deploy' => $deployCharAry,
            'undeploy' => $undeployCharAry,
        ];

    }
}
