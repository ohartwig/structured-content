<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\TCA;

use Koh\StructuredContent\Domain\Enum\Audience;
use Koh\StructuredContent\Domain\Enum\Channel;
use Koh\StructuredContent\Domain\Enum\Tone;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class ContextFields
{
    private const LLL = 'LLL:EXT:structured_content/Resources/Private/Language/locallang_tca.xlf:';

    public static function addToTable(string $table): void
    {
        $audienceItems = [
            ['label' => '', 'value' => ''],
        ];
        foreach (Audience::cases() as $audience) {
            $audienceItems[] = ['label' => ucfirst($audience->value), 'value' => $audience->value];
        }

        $toneItems = [
            ['label' => '', 'value' => ''],
        ];
        foreach (Tone::cases() as $tone) {
            $toneItems[] = ['label' => ucfirst($tone->value), 'value' => $tone->value];
        }

        $channelItems = [];
        foreach (Channel::cases() as $channel) {
            $channelItems[] = ['label' => $channel->label(), 'value' => $channel->value];
        }

        $columns = [
            'ai_context_audience' => [
                'exclude' => true,
                'label' => self::LLL . 'ai_context_audience.label',
                'description' => self::LLL . 'ai_context_audience.description',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectSingle',
                    'items' => $audienceItems,
                    'default' => '',
                ],
            ],
            'ai_context_tone' => [
                'exclude' => true,
                'label' => self::LLL . 'ai_context_tone.label',
                'description' => self::LLL . 'ai_context_tone.description',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectSingle',
                    'items' => $toneItems,
                    'default' => '',
                ],
            ],
            'ai_context_channels' => [
                'exclude' => true,
                'label' => self::LLL . 'ai_context_channels.label',
                'description' => self::LLL . 'ai_context_channels.description',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectCheckBox',
                    'items' => $channelItems,
                    'default' => '',
                ],
            ],
        ];

        ExtensionManagementUtility::addTCAcolumns($table, $columns);
        ExtensionManagementUtility::addToAllTCAtypes(
            $table,
            '--div--;' . self::LLL . 'tab.ai_context, ai_context_audience, ai_context_tone, ai_context_channels',
        );
    }
}
