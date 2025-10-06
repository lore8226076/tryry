<?php

namespace App\Console\Commands;

use App\Models\GddbItems;
use App\Models\GddbSurgameCards;
use App\Models\GddbSurgameEqEnhance;
use App\Models\GddbSurgameEqMaster;
use App\Models\GddbSurgameEqQuility;
use App\Models\GddbSurgameEqRefine;
use App\Models\GddbSurgameEquipment;
use App\Models\GddbSurgameGrade;
use App\Models\GddbSurgameHeroes;
use App\Models\GddbSurgameItemPackage;
use App\Models\GddbSurgameJourney;
use App\Models\GddbSurgameJourneyReward;
use App\Models\GddbSurgameJourneyStarReward;
use App\Models\GddbSurgameLevels;
use App\Models\GddbSurgameLevelUps;
use App\Models\GddbSurgameMobs;
use App\Models\GddbSurgamePassiveReward;
use App\Models\GddbSurgamePlayerLvUp;
use App\Models\GddbSurgameRankFuncs;
use App\Models\GddbSurgameRankUps;
use App\Models\GddbSurgameSkills;
use App\Models\GddbSurgameStages;
use App\Models\GddbSurgameTalent;
use App\Models\GddbSurgameTalentDraw;
use App\Models\GddbSurgameTreasure;
use App\Models\Tasks;
use Illuminate\Console\Command;

class ImportSurgameItems extends Command
{
    protected $signature = 'surgame:init';

    protected $description = '將 surgame item JSON 存入資料庫';

    public function handle()
    {
        $this->info('開始匯入surgame資料');

        // $this->migrateCards();
        // $this->migrateHeros();
        // $this->migrateLevelUps();
        // $this->migrateLevels();
        // $this->migrateMobs();
        // $this->migratePassiveRewards();
        // $this->migrateRankFuncs();
        // $this->migrateRankUps();
        // $this->migrateSkills();
        // $this->migrateStages();
        // // 2025-08-08
        // $this->migratePlayerLvUp();
        // $this->migrateEquipment();
        // $this->migrateEqEnhance();
        // $this->migrateEqRefine();
        // $this->migrateEqQuility();
        // $this->migrateEqMaster();
        // $this->migrateTalent();
        // $this->migrateTalentDraw();
        // $this->migrateSurgameQuest();
        // $this->migrateGrade();
        // $this->migrateItemPackage();
        // $this->migrateStarRewards();
        // $this->migrateJourneys();
        $this->migrateTreasure();

        $this->info('所有資料匯入成功!');

        return 0;
    }

    private function migrateCards()
    {
        $this->info('開始匯入 Surgame Cards 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/105_surgame_cards.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgameCards::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'name' => ['name', null],
            'desc' => ['desc', null],
            'sprite_name' => ['sprite_name', null],
            'card_type' => ['card_type', null],
            'owner_hero_id' => ['owner_hero_id', null],
            'synergy_hero_id' => ['synergy_hero_id', null],
            'modifiers' => ['modifiers', null],
            'num' => ['num', null],
        ];
        $results = $this->storeInputData(GddbSurgameCards::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame Cards 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');

    }

    private function migrateHeros()
    {
        $this->info('開始匯入 Surgame Heros 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/102_surgame_heroes.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgameHeroes::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'name' => ['name', null],
            'icon' => ['Icon', null],
            'card' => ['card', null],
            'prefab' => ['Prefab', null],
            'skill_01' => ['Skill 01', null],
            'skill_02' => ['Skill 02', null],
            'skill_02_evo' => ['Skill 02 Evo', null],
            'rarity' => ['Rarity', null],
            'style_group' => ['StyleGroup', null],
            'rank_up_group' => ['RankUpGroup', null],
            'rank_func_group' => ['RankFuncGroup', null],
            'level_group' => ['LevelGroup', null],
            'chain_skill' => ['ChainSkill', null],
            'icon_main_skill' => ['Icon_MainSkill', null],
            'icon_talent' => ['Icon_Talent', null],
            'icon_passive' => ['Icon_Passive', null],
            'unique_id' => ['Id', null],
            'convert_item_id' => ['Shards', -1],
            'element' => ['element', 0],
        ];
        $results = $this->storeInputData(GddbSurgameHeroes::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame Heros 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');
    }

    private function migrateLevelUps()
    {
        $this->info('開始匯入 Surgame LevelUps 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/107_surgame_levelUp.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgameLevelUps::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'target_level' => ['Lv', 0],
            'base_item_id' => ['Cost', 0],
            'base_item_amount' => ['Cost_Amount', 0],
            'extra_item_id' => ['ExCost', 0],
            'extra_item_amount' => ['ExCost_Amount', 0],
        ];
        $results = $this->storeInputData(GddbSurgameLevelUps::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame LevelUps 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');

    }

    private function migrateLevels()
    {
        $this->info('開始匯入 Surgame Levels 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/106_surgame_level.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgameLevels::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'group_id' => ['GroupId', 0],
            'level' => ['Level', 0],
            'base_atk' => ['ATK', 0],
            'base_hp' => ['HP', 0],
            'base_def' => ['DEF', 0],
        ];
        $results = $this->storeInputData(GddbSurgameLevels::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame Levels 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');

    }

    private function migrateMobs()
    {
        $this->info('開始匯入 Surgame Mobs 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/103_surgame_mobs.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgameMobs::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'prefab' => ['prefab', null],
            'hp' => ['hp', null],
            'atk' => ['atk', null],
            'def' => ['def', null],
            'exp' => ['exp', null],
            'gold' => ['gold', null],
        ];
        $results = $this->storeInputData(GddbSurgameMobs::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame Mobs 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');

    }

    private function migratePassiveRewards()
    {
        $this->info('開始匯入 Surgame PassiveRewards 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/115_surgame_passiveRewards.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgamePassiveReward::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'now_stage' => ['NowStage', 1],
            'rand_reward' => ['RandReward', []],
            'hour_coin' => ['HourCoin', 0],
            'hour_exp' => ['HourExp', 0],
            'hour_crystal' => ['HourCrystal', 0],
            'hour_paint' => ['HourPaint', 0],
            'hour_xp' => ['HourXp', 0],
        ];
        $results = $this->storeInputData(GddbSurgamePassiveReward::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame PassiveRewards 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');

    }

    private function migrateRankFuncs()
    {
        $this->info('開始匯入 Surgame RankFuncs 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/108_surgame_rankFunc.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgameRankFuncs::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'group_id' => ['GroupId', 0],
            'required_star_rank' => ['Rank', null],
            'name' => ['Name', null],
            'description' => ['Description', null],
            'type' => ['Type', null],
            'func_data' => ['FuncData', null],
            'atk_grow' => ['ATK_Grow', null],
            'hp_grow' => ['HP_Grow', null],
            'def_grow' => ['DEF_Grow', null],
        ];
        $results = $this->storeInputData(GddbSurgameRankFuncs::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame RankFuncs 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');

    }

    private function migrateRankUps()
    {
        $this->info('開始匯入 Surgame RankUps 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/109_surgame_rankUp.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgameRankUps::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'group_id' => ['GroupID', 0],
            'star_level' => ['Rank', null],
            'base_item_id' => ['Cost', null],
            'base_item_amount' => ['Cost_Amount', null],
            'extra_item_id' => ['ExCost', null],
            'extra_item_amount' => ['ExCost_Amount', null],
        ];
        $results = $this->storeInputData(GddbSurgameRankUps::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame RankUps 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');

    }

    private function migrateSkills()
    {
        $this->info('開始匯入 Surgame Skills 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/101_surgame_skills.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgameSkills::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'ui_sprite_name' => ['UISpriteName', null],
            'prefab' => ['Prefab', null],
        ];
        $results = $this->storeInputData(GddbSurgameSkills::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame Skills 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');

    }

    private function migrateStages()
    {
        $this->info('開始匯入 Surgame Stages 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/104_surgame_stages.txt';
        $datas = $this->convertData($url, 'txt');
        GddbSurgameStages::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'map_key_name' => ['map key name', null],
            'scene_name' => ['scene_name', null],
            'scene_logic' => ['scene_logic', null],
            'scene_ui' => ['scene_ui', null],
        ];
        $results = $this->storeInputData(GddbSurgameStages::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Surgame Stages 資料匯入失敗');

            return 0;
        }
        $this->info('匯入完成');

    }

    private function migratePlayerLvUp()
    {
        $this->info('開始匯入 Surgame PlayerLvUp 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/100_surgame_playerLvUp.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgamePlayerLvUp::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'account_lv' => ['AcountLV', null],
            'xp' => ['XP', 0],
            'reward' => ['Reward', []],
        ];

        $res = $this->storeInputData(GddbSurgamePlayerLvUp::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('PlayerLvUp 匯入失敗');

            return 0;
        }
        $this->info('PlayerLvUp 匯入完成');
    }

    private function migrateEquipment()
    {
        $this->info('開始匯入 Surgame Equipment 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/110_surgame_equipment.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameEquipment::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'unique_id' => ['ID', null],
            'type' => ['Type', null],
            'name' => ['Name', null],
            'quility' => ['Quility', 0],
            'base_atk' => ['Basic_ATK', 0],
            'base_hp' => ['Basic_HP', 0],
            'base_def' => ['Basic_DEF', 0],
        ];

        $res = $this->storeInputData(GddbSurgameEquipment::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('Equipment 匯入失敗');

            return 0;
        }
        // 補回 item_id 欄位
        $equipments = GddbSurgameEquipment::get();
        foreach ($equipments as $eq) {
            $itemId = GddbItems::where(['region' => 'Surgame', 'category' => 'Equipment', 'manager_id' => $eq->unique_id])->value('item_id');
            if ($itemId) {
                $eq->item_id = $itemId;
                $eq->save();
            }
        }

        // 依據type定義可裝備位置, 取type最後一個數字 -1 為位置
        foreach ($equipments as $eq) {
            // 取得整個字串最後一個文字
            $lastChar = substr($eq->type, -1);
            if (is_numeric($lastChar)) {
                $position = (int) $lastChar - 1;
                if ($position >= 0 && $position <= 5) {
                    $eq->slot_position = $position;
                    $eq->save();
                }
            }
        }

        $this->info('Equipment 匯入完成');
    }

    private function migrateEqEnhance()
    {
        $this->info('開始匯入 Surgame EqEnhance 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/111_surgame_eq_enhance.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameEqEnhance::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'lv' => ['Lv', null],
            'min_slot_lv' => ['MinSlotLv', 0],
            'cost' => ['Cost', 0],
            'cost_amount' => ['Cost_Amount', 0],
            'ex_cost' => ['ExCost', 0],
            'ex_cost_amount' => ['ExCost_Amount', 0],
        ];

        $res = $this->storeInputData(GddbSurgameEqEnhance::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('EqEnhance 匯入失敗');

            return 0;
        }
        $this->info('EqEnhance 匯入完成');
    }

    private function migrateEqRefine()
    {
        $this->info('開始匯入 Surgame EqRefine 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/112_surgame_eq_refine.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameEqRefine::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'lv' => ['Lv', null],
            'min_enhance_lv' => ['MinEnhanceLv', 0],
            'cost' => ['Cost', 0],
            'cost_amount' => ['Cost_Amount', 0],
            'success_rate' => ['Basic_Rate', 0],
        ];

        $res = $this->storeInputData(GddbSurgameEqRefine::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('EqRefine 匯入失敗');

            return 0;
        }
        $this->info('EqRefine 匯入完成');
    }

    private function migrateEqQuility()
    {
        $this->info('開始匯入 Surgame EqQuility 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/113_surgame_eq_quility.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameEqQuility::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'quility' => ['Quility', null],
            'name' => ['Name', null],
            'recycle_value' => ['RecycleValue', 0],
            'ex_attr_amount' => ['ExAttr_Amount', 0],
            'ex_attr_atk' => ['ExAttr_ATK', null],
            'ex_attr_hp' => ['ExAttr_HP', null],
            'ex_attr_def' => ['ExAttr_DEF', null],
        ];

        $res = $this->storeInputData(GddbSurgameEqQuility::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('EqQuility 匯入失敗');

            return 0;
        }
        $this->info('EqQuility 匯入完成');
    }

    private function migrateEqMaster()
    {
        $this->info('開始匯入 Surgame EqMaster 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/114_surgame_eq_master.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameEqMaster::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'type' => ['Type', null],
            'lv' => ['Lv', 0],
            'necessary_min_lv' => ['NecessaryMinLv', 0],
            'atk_bonus' => ['ATK_Bunus', 0],
            'hp_bonus' => ['HP_Bunus', 0],
            'def_bonus' => ['DEF_Bunus', 0],
        ];
        $res = $this->storeInputData(GddbSurgameEqMaster::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('EqMaster 匯入失敗');

            return 0;
        }
        $this->info('EqMaster 匯入完成');
    }

    private function migrateTalent()
    {
        $this->info('開始匯入 Surgame Talent 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/116_surgame_talent.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameTalent::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'card_id' => ['Card_ID', null],
            'lv' => ['Lv', 0],
            'icon' => ['Icon', null],
            'name' => ['Name', null],
            'description' => ['Description', null],
            'func' => ['Func', null],
            'parament' => ['Parament', 0],
        ];

        $res = $this->storeInputData(GddbSurgameTalent::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('Talent 匯入失敗');

            return 0;
        }
        $this->info('Talent 匯入完成');
    }

    private function migrateTalentDraw()
    {
        $this->info('開始匯入 Surgame TalentDraw 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/117_surgame_talentDraw.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameTalentDraw::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'account_lv' => ['AcountLV', null],
            'cost' => ['Cost', 0],
            'amount' => ['Amount', 0],
            'card_pool' => ['CardPool', []],
        ];

        $res = $this->storeInputData(GddbSurgameTalentDraw::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('TalentDraw 匯入失敗');

            return 0;
        }
        $this->info('TalentDraw 匯入完成');
    }

    private function migrateItemPackage()
    {
        $this->info('開始匯入 Surgame Item Package 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/124_surgame_itemPackage.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameItemPackage::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'item_id' => ['PackageID', 0],
            'manager_id' => ['ManagerId', 0],
            'auto_use' => ['AutoUse', 0],
            'choice_box' => ['ChoiceBox', 0],
            'random_times' => ['RandomTimes', 0],
            'use_necessary' => ['UseNecessary', 0],
            'contents' => ['Contents', []],
            'note' => ['Note', ''],
        ];

        $res = $this->storeInputData(GddbSurgameItemPackage::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('Item Package 匯入失敗');

            return 0;
        }

        $this->info('Item Package 匯入完成');
    }

    private function migrateGrade()
    {
        $this->info('開始匯入 Surgame Grade 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/119_surgame_grade.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameGrade::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'unique_id' => ['managerID', 0],
            'grade_group' => ['Grade', ''],
            'grade_level' => ['Level', 0],
            'grade_name' => ['Name', ''],
            'reward' => ['Reward', ''],
            'func_key' => ['SpcReward', ''],
            'func_desc' => ['SpcRewardDescription', ''],
        ];

        $res = $this->storeInputData(GddbSurgameGrade::class, $columnMappingData, $datas);
        $tasks = Tasks::whereNotNull('series_id')
            ->get()
            ->groupBy('series_id')
            ->map(fn ($group) => $group->pluck('id')->toArray());
        $data = GddbSurgameGrade::get()->map(function ($item, $index) use ($tasks) {
            $item->related_level = $index + 1;
            if (isset($tasks[$item->unique_id])) {
                $item->quests = $tasks[$item->unique_id];
            }
            $item->save();

            return $item;
        });
        if (! $res['success']) {
            $this->info('Grade 匯入失敗');

            return 0;
        }

        $this->info('Grade 匯入完成');
    }

    public function migrateSurgameQuest()
    {
        $this->info('開始匯入 Surgame Quests 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/120_surgame_quest.txt';
        $datas = $this->convertData($url, 'txt');

        Tasks::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'id' => ['id', 0],
            'description' => ['description', ''],
            'type' => ['type', null],
            'localization_name' => ['localization_name', ''],
            'action' => ['action', null],
            'check_id' => ['check_id', null],
            'count' => ['count', 0],
            'reward' => ['reward', []],
            'start_at' => ['start_at', null],
            'end_at' => ['end_at', null],
            'prev_task_id' => ['prev_task_id', 0],
            'next_task_id' => ['next_task_id', 0],
            'is_auto_complete' => ['is_auto_complete', 1],
            'repeat_type' => ['repeat_type', null],
            'is_active' => ['is_active', 1],
            'series_id' => ['series_id', null],
            'condition' => ['id', 0],
            'auto_assign' => ['is_active', 0],
        ];

        $res = $this->storeInputData(Tasks::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('Tasks 匯入失敗');

            return 0;
        }

        // 重新將condition 重寫
        $this->convertTaskData(Tasks::class, $datas);
        if (! $res['success']) {
            $this->info('Tasks 資料轉換失敗');

            return 0;
        }

        $this->info('Tasks 匯入完成');
    }

    // 星級挑戰
    public function migrateStarRewards()
    {
        $this->info('開始匯入 Surgame StarRewards 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/123_surgame_starsRewards.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameJourneyStarReward::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'unique_id' => ['ID', 0],
            'type' => ['Type', ''],
            'star_count' => ['Stars', 0],
            'rewards' => ['Rewards', ''],
        ];

        $res = $this->storeInputData(GddbSurgameJourneyStarReward::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('StarRewards 匯入失敗');

            return 0;
        }

        $this->info('StarRewards 匯入完成');
    }

    // 冒險之旅
    public function migrateJourneys()
    {
        $this->info('開始匯入 Surgame Journeys 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/122_surgame_journey.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameJourney::truncate();
        $this->info('清空當前資料');
        $columnMappingData = [
            'unique_id' => ['ID', 0],
            'name' => ['Name', ''],
            'stage_id' => ['StageID', 0],
            'over_power' => ['Overpower', 0],
        ];
        $results = $this->storeInputData(GddbSurgameJourney::class, $columnMappingData, $datas);
        if (! $results['success']) {
            $this->info('Journeys 資料匯入失敗');

            return 0;
        }

        GddbSurgameJourneyReward::truncate();
        $this->info('清空當前波次獎勵資料');
        // 寫入對應波次獎勵
        $journeys = GddbSurgameJourney::get();
        $datas = collect($datas)->keyBy('ID');
        foreach ($journeys as $journey) {
            for ($i = 10; $i <= 20; $i += 5) {
                $ary = [
                    'journey_id' => $journey->id,
                    'wave' => $i,
                    'rewards' => $datas[$journey->unique_id]['Wave'.$i.'_Reward'] ?? null,
                ];
                GddbSurgameJourneyReward::updateOrCreate(
                    ['journey_id' => $journey->id, 'wave' => $i],
                    $ary
                );
            }
        }

        $this->info('匯入完成');
    }

    // 118_surgame_treasure
    public function migrateTreasure()
    {
        $this->info('開始匯入 Surgame Treasure 資料');
        $url = 'https://r2dev.wow-dragon.com/gddatabase/118_surgame_treasure.txt';
        $datas = $this->convertData($url, 'txt');

        GddbSurgameTreasure::truncate();
        $this->info('清空當前資料');

        $columnMappingData = [
            'unique_id' => ['TreasureID', 0],
            'name' => ['Name', ''],
            'quality_level' => ['Quility', null],
            'show_label' => ['ShowLabel', null],
            'element' => ['element', null],
            'target_hero' => ['TargetHero', null],
            'atk_bonus' => ['ATK_Bonus', 0],
            'hp_bonus' => ['HP_Bonus', 0],
            'def_bonus' => ['DEF_Bonus', 0],
            'description' => ['Description', ''],
            'func_card' => ['FuncCard', ''],
            'atk_add_p' => ['ATK_Add_P', 0],
            'hp_add_p' => ['HP_Add_P', 0],
            'fuse_material_type' => ['Fuse_MaterialType', null],
            'material_item_id' => ['MaterialItemId', null],
            'fuse_material_item_id' => ['Fuse_MaterialID', null],
            'fuse_common_material_item_id' => ['Fuse_C_MaterialID', null],
            'need_two_material' => ['Fuse_W_Material', 0],
        ];

        $res = $this->storeInputData(GddbSurgameTreasure::class, $columnMappingData, $datas);
        if (! $res['success']) {
            $this->info('Treasure 匯入失敗');
            return 0;
        }
        // 補回 item_id 欄位
        $treasures = GddbSurgameTreasure::get();
        foreach ($treasures as $tr) {
            $itemId = GddbItems::where(['region' => 'Surgame', 'category' => 'Treasure', 'manager_id' => $tr->unique_id])->value('item_id');
            if ($itemId) {
                $tr->item_id = $itemId;
                $tr->save();
            }
        }

        $this->info('Treasure 匯入完成');
    }

    // 轉換資料
    private function convertData($item_data_url, $data_type = 'txt')
    {
        // 讀取檔案內容
        $file_contents = file_get_contents($item_data_url);
        if ($file_contents === false) {
            return ['error' => '無法讀取檔案'];
        }

        if ($data_type === 'txt') {
            // 去掉 BOM
            $file_contents = preg_replace('/^\xEF\xBB\xBF/', '', $file_contents);

            $lines = explode("\n", $file_contents);
            unset($file_contents);

            // 去空行並重整索引
            $lines = array_values(array_filter(array_map('trim', $lines)));

            // 如果前兩行都是 // 註解，就丟掉第一行
            if (
                isset($lines[0]) && strpos($lines[0], '//') === 0 &&
                isset($lines[1]) && strpos($lines[1], '//') === 0
            ) {
                array_shift($lines);
            }

            // 取 header，並去掉開頭的 //
            $headerLine = array_shift($lines);
            $headerLine = preg_replace('/^\/\/\s*/', '', $headerLine);
            $header = str_getcsv($headerLine, "\t");

            $datas = [];
            foreach ($lines as $line) {
                if ($line === '' || strpos($line, '//') === 0) {
                    continue;
                }

                $data = str_getcsv($line, "\t");
                $data = array_pad($data, count($header), null);
                $data = array_slice($data, 0, count($header));
                if (! isset($data[0])) {
                    continue;
                }

                $row = array_combine($header, $data);

                $datas[] = $row;

            }

            return $datas;
        }

        if ($data_type == 'csv') {
            // 將檔案內容按行分割
            $lines = explode("\r\n", $file_contents);
            unset($file_contents); // 釋放記憶體
            foreach ($lines as $line) {
                $data = explode(',', $line);
                // key,en_info,zh_info
                $datas[] = [
                    'key' => $data[0],
                    'en_info' => $data[1] ?? null,
                    'zh_info' => $data[2] ?? null,
                ];
            }
        }

        return $datas;
    }

    // 在您的匯入 Command 檔案中

    private function storeInputData($modelClass, $columnMappingData, $datas)
    {
        try {
            $model = new $modelClass;
            $casts = method_exists($model, 'getCasts') ? $model->getCasts() : [];

            foreach ($datas as $row) {
                $insert = [];

                foreach ($columnMappingData as $modelColumnName => [$txtColName, $default]) {
                    $val = $row[$txtColName] ?? $default;

                    if ($val === '') {
                        $val = null;
                    }

                    if ($val === null) {
                        $val = $default;
                    }
                    // ===================================

                    if (isset($casts[$modelColumnName])) {
                        $cast = strtolower($casts[$modelColumnName]);

                        if (in_array($cast, ['array', 'json', 'collection'], true)) {
                            if (is_string($val)) {
                                $trim = trim($val);

                                if ($trim === '' || strtoupper($trim) === 'NULL') {
                                    $val = [];
                                } elseif ($trim[0] === '[' && substr($trim, -1) === ']') {
                                    $json = str_replace("'", '"', $trim);
                                    $decoded = json_decode($json, true);

                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        $val = $decoded;
                                    }
                                }
                            }
                        }
                        if (in_array($cast, ['datetime', 'date', 'timestamp'], true)) {
                            try {
                                if ($val === 0 || $val === '0' || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') {
                                    $val = $default;
                                } else {
                                    $val = \Carbon\Carbon::parse($val);
                                }
                            } catch (\Exception $e) {
                                $val = $default;
                            }
                        }
                        if (in_array($cast, ['integer'], true)) {
                            try {
                                if (is_numeric($val)) {
                                    $val = (int) $val;
                                }
                            } catch (\Exception $e) {
                                $val = $default; // 或 0
                            }
                        }
                    }

                    $insert[$modelColumnName] = $val;
                }

                $modelClass::create($insert);
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            // 在日誌中加上錯誤的行數據，方便除錯
            $context = isset($insert) ? ['data' => $insert, 'trace' => $e->getTraceAsString()] : ['trace' => $e->getTraceAsString()];
            \Log::error('匯入失敗：'.$e->getMessage(), $context);

            return ['success' => false];
        }
    }

    private function convertTaskData($modelClass, $datas)
    {
        $models = $modelClass::get();
        if (! empty($modelClass)) {

            $models->each(function ($model, $index) use ($datas) {
                if (isset($datas[$index])) {
                    $model->condition = [
                        'action' => $datas[$index]['action'],
                        'count' => intval($datas[$index]['count']),
                    ];
                    $model->save();
                }
            });

            return ['success' => true];
        }
    }
}
