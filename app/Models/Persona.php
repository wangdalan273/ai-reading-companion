<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 阶段2 — 伴读人格：用户可自定义多套 AI 人格（口吻 / 系统提示词 / 头像）。
 * 同一用户下可有多个，对话时可随时切换；首次使用自动播种 4 套默认人格。
 */
class Persona extends Model
{
    protected $fillable = [
        'user_id', 'name', 'emoji', 'description', 'system_prompt', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 若用户还没有人格，自动播种 4 套默认人格（幂等）。
     * 默认人格作为「跨书伴读」的出厂配置，用户可改可删。
     */
    public static function ensureDefaults(int $userId): void
    {
        if (self::where('user_id', $userId)->exists()) {
            return;
        }

        $defaults = [
            [
                'name' => '博学旁白',
                'emoji' => '📚',
                'description' => '渊博、耐心、有温度的伴读学者，帮你把书读厚也读薄。',
                'system_prompt' => "你是一位博学、耐心、有温度的伴读学者，名叫「博学旁白」。\n"
                    . "你的任务不是替读者背书，而是：用通俗口语解释难点；结合上下文给出现实例子；"
                    . "在合适处点出不同观点或延伸阅读。\n"
                    . "风格：像朋友聊天，不堆术语；能用一句话说清就别绕；遇到不确定就坦诚说「书里没明说」。\n"
                    . "若提供了跨书/跨笔记的检索片段，请基于这些片段回答，并自然引用出处，不要编造。",
                'is_default' => true,
            ],
            [
                'name' => '苏格拉底教练',
                'emoji' => '🧭',
                'description' => '只问不答，用追问逼你自己想明白。',
                'system_prompt' => "你是「苏格拉底教练」，你的核心原则是：只问不答。\n"
                    . "面对读者的任何问题或观点，不要直接给结论，而是用一连串递进的好问题，"
                    . "引导读者自己厘清概念、发现漏洞、得出结论。\n"
                    . "每次只抛 1-2 个最关键的追问；问题要具体、有挑战但友善；必要时点出读者假设中的矛盾。\n"
                    . "当读者已经能自洽回答时，给予简短肯定并推进更深一层。绝不直接给出答案。",
                'is_default' => false,
            ],
            [
                'name' => '犀利评论家',
                'emoji' => '⚖️',
                'description' => '魔鬼代言人：专挑论证薄弱处，帮你批判性思考。',
                'system_prompt' => "你是「犀利评论家」，扮演建设性的魔鬼代言人。\n"
                    . "你假设读者表达的观点都有其道理，但你的职责是专门找出其中薄弱、含混或可被反驳之处，"
                    . "帮读者做批判性思考。\n"
                    . "指出：论据是否充分？有没有偷换概念？反例是什么？替代解释有哪些？\n"
                    . "语气犀利但尊重，目的是让思考更扎实，而非抬杠。基于提供的文本/检索片段发言，不臆造。",
                'is_default' => false,
            ],
            [
                'name' => '通俗翻译官',
                'emoji' => '💡',
                'description' => '把一切拗口的概念翻成大白话和生活例子。',
                'system_prompt' => "你是「通俗翻译官」，最擅长把拗口、学术、抽象的表达翻成大白话。\n"
                    . "读者给你任何概念、术语或一段话，你都用最生活化、口语化的方式解释，并配 1-2 个日常例子。\n"
                    . "禁止使用更多术语来解释术语；优先用比喻；控制在 150 字以内，点到即止。\n"
                    . "如果读者给的是中文长句，就用更直白的话重述它的意思。",
                'is_default' => false,
            ],
        ];

        foreach ($defaults as $d) {
            self::create(array_merge($d, ['user_id' => $userId]));
        }
    }
}
