<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Incidents Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Incident\Model;

use Uhifadhi\Incident\Enum\BehaviorBlockEnum;

/**
 * WHAT A FILER ANSWERED IN STEP 2'S BLOCKS — one value, on its way from the form
 * to the record.
 *
 * THE MONEY FIGURE TRAVELS BESIDE THE REST rather than inside it, because it does
 * not live where the rest lives: {@see $claimed} is the claimed figure on the
 * incident, and every other answer is kept per block. One value carries both so a
 * caller cannot file the answers and forget the figure.
 *
 * @see \Uhifadhi\Incident\Service\IncidentBlockAnswerService  what reads it off a form
 */
final readonly class BlockAnswers
{
    /**
     * @param array<string, array<string, mixed>> $values  keyed by the block's wire value; a repeating block keeps `rows`
     * @param int|null                            $claimed the figure the money block asked at filing, in whole units
     */
    public function __construct(
        public array $values = [],
        public ?int $claimed = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->values && null === $this->claimed;
    }

    /** @return array<string, mixed> */
    public function forBlock(BehaviorBlockEnum $block): array
    {
        return $this->values[$block->value] ?? [];
    }

    /** One answer to a question asked once, or null where it was not answered. */
    public function single(BehaviorBlockEnum $block, string $key): ?string
    {
        $answer = $this->forBlock($block)[$key] ?? null;

        return \is_string($answer) ? $answer : null;
    }

    /**
     * The rows of a block that repeats, in the order the filer built them.
     *
     * @return list<array<string, string>>
     */
    public function rows(BehaviorBlockEnum $block): array
    {
        $rows = $this->forBlock($block)['rows'] ?? [];
        if (!\is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $cells = [];
            foreach ($row as $key => $value) {
                if (\is_string($key) && \is_string($value)) {
                    $cells[$key] = $value;
                }
            }
            $out[] = $cells;
        }

        return $out;
    }
}
