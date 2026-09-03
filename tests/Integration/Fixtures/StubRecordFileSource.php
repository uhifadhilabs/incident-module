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

namespace Uhifadhi\Incident\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Storage\Enum\FileKindEnum;
use Uhifadhi\Storage\Enum\GuardStateEnum;
use Uhifadhi\Storage\Model\FileEntry;
use Uhifadhi\Storage\Model\FileGuard;
use Uhifadhi\Storage\Registry\FileSourceInterface;

/**
 * THE MODULE ON THE OTHER SIDE OF THE SEAM, played by a fixture.
 *
 * It stands where patrol-module stands in a real deployment: it owns records this
 * bundle knows nothing about, and it alone can say which photographs belong to
 * one of them. That is exactly the point of the test — the incidents report flow
 * must be able to draw an observation's photographs while naming no class, no
 * route and no key prefix of the module that holds them.
 *
 * It answers to the SINGULAR wire token and to its own module slug, because that
 * is the pair a real seam carries: patrol sends `source=patrol` and calls itself
 * "patrols" on the hub.
 *
 * Everything it publishes is synthetic and deliberately NOT a client's words.
 */
final class StubRecordFileSource implements FileSourceInterface
{
    public const string SLUG = 'fieldwork';

    /** The record whose photographs this fixture holds. */
    public const string RECORD = '01a03fe3-dc9f-797f-ad0f-8cdc5e81e32d';

    public const string TOKEN = 'fieldwork';

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function moduleLabel(): string
    {
        return 'Fieldwork';
    }

    public function attachesTo(): string
    {
        return 'a record’s photographs';
    }

    /**
     * NOTHING ON THE HUB. This fixture speaks for exactly one seam — "give me
     * that record's photographs" — and publishing to /files as well would make
     * every hub-wide assertion in this suite about a module that exists only to
     * stand on the other side of a query string.
     */
    public function files(): iterable
    {
        return [];
    }

    public function filesForRecord(string $source, string $recordUuid): iterable
    {
        if (self::SLUG !== strtolower(trim($source)) || self::RECORD !== $recordUuid) {
            return [];
        }

        return [
            self::entry('fieldwork/rec-1/first.jpg', 'first.jpg', '2026-08-22 08:15:00'),
            self::entry('fieldwork/rec-1/second.jpg', 'second.jpg', '2026-08-22 08:16:00'),
        ];
    }

    public function claimsKey(string $key): bool
    {
        return str_starts_with($key, self::SLUG.'/');
    }

    public function guard(string $key, ?UserInterface $user): FileGuard
    {
        return new FileGuard(GuardStateEnum::Locked, 'The record keeps it', 'Because it does.');
    }

    private static function entry(string $key, string $name, string $takenAt): FileEntry
    {
        return new FileEntry(
            key: $key,
            name: $name,
            mimeType: 'image/jpeg',
            byteSize: 204_800,
            ownerLabel: 'REC-0001',
            ownerUrl: '/fieldwork/rec-1',
            moduleSlug: self::SLUG,
            moduleLabel: 'Fieldwork',
            areaSlug: 'sample',
            areaLabel: 'Sample Area',
            takenAt: new \DateTimeImmutable($takenAt),
            thumbKey: $key.'.thumb.jpg',
            kind: FileKindEnum::Photo,
        );
    }
}
