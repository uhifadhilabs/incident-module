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

namespace UhifadhiLabs\Incident\Tests\Integration\Overview;

use Uhifadhi\Model\Widget;
use Uhifadhi\Overview\AttentionItem;
use Uhifadhi\Overview\AttentionSeverity;
use Uhifadhi\Overview\ContributesStylesheetInterface;
use Uhifadhi\Overview\MapLayer;
use Uhifadhi\Overview\NowTile;
use Uhifadhi\Overview\OverviewCopyProviderInterface;
use Uhifadhi\Overview\PulseEvent;
use UhifadhiLabs\Incident\Model\IncidentOverview;
use UhifadhiLabs\Incident\Model\IncidentOverviewWidgets;
use UhifadhiLabs\Incident\Overview\IncidentAttention;
use UhifadhiLabs\Incident\Overview\IncidentMapLayers;
use UhifadhiLabs\Incident\Overview\IncidentNowTiles;
use UhifadhiLabs\Incident\Overview\IncidentOverviewContributor;
use UhifadhiLabs\Incident\Overview\IncidentOverviewCopy;
use UhifadhiLabs\Incident\Overview\IncidentPulse;
use UhifadhiLabs\Incident\Tests\Integration\OverviewTestCase;
use UhifadhiLabs\Incident\UhifadhiLabsIncidentBundle;

/**
 * WHAT THIS MODULE PUTS ON THE HOST'S AREA OVERVIEW — the five contracts, each
 * answered against the same real register.
 *
 * The seam's whole claim is that a module contributes PARTS and the host draws
 * them without knowing what they are. So these assertions are about the parts:
 * a tile with an index and a subline, an item with a severity and an age, a
 * layer with a legend, a move with a state. Whether the host lays them out
 * correctly is the host's own test.
 */
final class IncidentOverviewSeamTest extends OverviewTestCase
{
    public function testEveryProviderAnswersForTheModulesOwnSlug(): void
    {
        foreach (self::SERVICES as $class => $id) {
            $provider = $this->service($id);
            self::assertInstanceOf($class, $provider);
            // Asked only where the module is installed, and this is the string
            // the host matches on — the same slug IncidentModuleProvider declares.
            self::assertSame('incidents', $provider->moduleSlug(), $class);
        }
    }

    public function testTheContributorHandsTheHostTheDesignsDeclaration(): void
    {
        $contributor = $this->contributor();

        self::assertSame(IncidentOverviewWidgets::group()->id, $contributor->group()->id);
        self::assertSame(
            array_map(static fn (Widget $widget) => $widget->id, IncidentOverviewWidgets::widgets()),
            array_map(static fn (Widget $widget) => $widget->id, $contributor->widgets()),
        );
        // A pattern per contributor: every plate is rendered out of this bundle's
        // own template namespace, which is why the host's page holds no markup.
        self::assertSame('@UhifadhiLabsIncident/overview/_w_%s.html.twig', $contributor->partialPattern());
        self::assertSame(
            '@UhifadhiLabsIncident/overview/_w_in_flow.html.twig',
            \sprintf($contributor->partialPattern(), 'in_flow'),
        );
    }

    /**
     * THE ONE SURFACE SOMEBODY ELSE RENDERS THIS MODULE'S MARKUP ON. Every
     * incidents page of the module's own extends `base.html.twig`, which links
     * incidents.css; the area overview extends the HOST'S layout, so without
     * this the category chips, the five-state flow bar and the money tone on a
     * contributed plate render naked. The interface is optional — a contributor
     * with no CSS of its own does not implement it — so what is pinned here is
     * that this one does.
     */
    public function testTheContributorTellsTheHostWhichStylesheetItsPlatesWear(): void
    {
        $contributor = $this->contributor();

        self::assertInstanceOf(ContributesStylesheetInterface::class, $contributor);
        // What AssetMapper serves the bundle's public/ under — the SAME string
        // base.html.twig links, because both read the bundle's own constant and
        // the path is therefore written once.
        self::assertSame('bundles/uhifadhilabsincident/incidents.css', $contributor->stylesheet());
        self::assertSame(UhifadhiLabsIncidentBundle::STYLESHEET, $contributor->stylesheet());
        self::assertStringContainsString(
            "constant('UhifadhiLabs\\\\Incident\\\\UhifadhiLabsIncidentBundle::STYLESHEET')",
            (string) file_get_contents(__DIR__.'/../../../templates/base.html.twig'),
        );
    }

    public function testTheContributorsContextIsOneReadingOfTheAreaTheCardsShare(): void
    {
        $area = $this->aRegister();

        $context = $this->contributor()->context($area, self::now());

        self::assertArrayHasKey('overview', $context);
        self::assertInstanceOf(IncidentOverview::class, $context['overview']);
        self::assertSame(8, $context['overview']->total);
    }

    public function testTheTwoRightNowTilesCarryTheirOwnProvenanceIndex(): void
    {
        $area = $this->aRegister();

        $tiles = $this->tiles()->nowTilesFor($area, self::now());

        self::assertSame(['IN·N1', 'IN·N2'], array_map(static fn (NowTile $tile) => $tile->index, $tiles));
        self::assertSame('Open incidents', $tiles[0]->label);
        self::assertSame('7', $tiles[0]->value);
        self::assertSame('1 reported · 3 verified · 3 in progress', $tiles[0]->subline);
        // Two of the seven are past their own category's term, and the strip is
        // allowed to say so — the one tone that may raise an alarm on its own.
        self::assertSame('2 past their term', $tiles[0]->alarm);
        self::assertSame(NowTile::TONE_BAD, $tiles[0]->tone);

        self::assertSame('Filed today', $tiles[1]->label);
        self::assertSame('3', $tiles[1]->value);
        self::assertSame('1 poaching · 1 conflict · 1 mortality', $tiles[1]->subline);
    }

    /**
     * ABSENT IS NOT ZERO. An area with no register puts NO tile in the row — not
     * a tile reading 0, which would claim the module measured and found none.
     */
    public function testAnAreaWithNoRegisterPutsNoTileInTheStripAtAll(): void
    {
        $area = $this->anArea('Quiet Area');
        $this->installTaxonomy();

        self::assertSame([], $this->tiles()->nowTilesFor($area, self::now()));
    }

    public function testPastTermWorkBecomesAnAttentionItemInTheModulesOwnWords(): void
    {
        $area = $this->aRegister();

        $items = $this->attention()->attentionFor($area, self::now());
        $late = array_values(array_filter($items, static fn (AttentionItem $item) => 'past term' === $item->kind));

        self::assertCount(2, $late);
        self::assertStringContainsString('INC-0008', $late[0]->headline);
        self::assertStringContainsString('72 h', $late[0]->headline);
        // Seventeen days against a 72-hour term is somebody's day; twelve days
        // against the same term is somebody's week. The module says which — the
        // host only sorts by it.
        self::assertSame(AttentionSeverity::Now, $late[0]->severity);
        self::assertSame('17 d', $late[0]->ageLabel);
        self::assertContains('Endulen', $late[0]->meta);
        self::assertStringContainsString('/incidents/INC-0008', $late[0]->url);
    }

    public function testUnpaidCompensationIsCarriedRatherThanUrgent(): void
    {
        $area = $this->aRegister();

        $items = $this->attention()->attentionFor($area, self::now());
        $money = array_values(array_filter($items, static fn (AttentionItem $item) => 'money' === $item->kind));

        self::assertCount(1, $money);
        self::assertSame(AttentionSeverity::Watch, $money[0]->severity);
        self::assertStringContainsString('4,500,000', $money[0]->headline);
        self::assertStringContainsString('1 claim', $money[0]->headline);
    }

    /** A good day is allowed to look like one. */
    public function testAQuietAreaRaisesNothing(): void
    {
        $area = $this->anArea('Quiet Area');
        $this->installTaxonomy();

        self::assertSame([], $this->attention()->attentionFor($area, self::now()));
    }

    public function testTheOpenIncidentsLayerShipsItsLegendEntryAndItsPoints(): void
    {
        $area = $this->aRegister();

        $layers = $this->layers()->mapLayersFor($area, self::now());

        self::assertSame(['incidents.open', 'incidents.done'], array_map(static fn (MapLayer $layer) => $layer->id, $layers));
        self::assertSame('Incidents', $layers[0]->groupLabel);
        self::assertSame('Open', $layers[0]->label);
        self::assertSame(7, $layers[0]->count);
        self::assertTrue($layers[0]->on);
        self::assertIsArray($layers[0]->features['features']);
        self::assertCount(7, $layers[0]->features['features']);
        // Off by default, and its legend entry is there all the same: a legend
        // that appeared with the data would be a legend nobody can rely on.
        self::assertFalse($layers[1]->on);
    }

    /**
     * A LAYER WITH NOTHING TO DRAW STILL SHIPS ITS LEGEND ENTRY, as an empty
     * FeatureCollection — "no open incidents today" is an answer.
     */
    public function testALayerWithNothingToDrawIsStillOnThePlate(): void
    {
        $area = $this->anArea('Quiet Area');
        $this->installTaxonomy();

        $layers = $this->layers()->mapLayersFor($area, self::now());

        self::assertCount(2, $layers);
        self::assertSame([], $layers[0]->features['features']);
        self::assertSame(0, $layers[0]->count);
    }

    public function testThePulseIsMovesAndNotRecords(): void
    {
        $area = $this->aRegister();
        $now = self::now();

        $moves = $this->pulse()->pulseFor($area, $now->modify('-18 hours'), $now);

        // Six moves since yesterday evening, newest first — and a FILING is a
        // move like any other: three incidents were filed this morning and three
        // transitions were made, and the pulse is the whole morning rather than
        // the transitions somebody thought interesting.
        self::assertSame(
            ['INC-0003', 'INC-0003', 'INC-0001', 'INC-0005', 'INC-0002', 'INC-0001'],
            array_map(static fn (PulseEvent $event) => $event->recordRef, $moves),
        );
        self::assertSame('moved to', $moves[0]->move);
        self::assertSame('verified', $moves[0]->state);
        // The filing right beneath it landed in no new place, so it wears no chip.
        self::assertSame('note added', $moves[1]->move);
        self::assertNull($moves[1]->state);
        // The module's own chip class, so the host's neutral row can wear the
        // module's colour without knowing what a verification is.
        self::assertSame('ver', $moves[0]->stateClass);
        self::assertStringContainsString('/incidents/INC-0003', $moves[0]->url);
        self::assertContains('S. Laizer', $moves[0]->meta);
    }

    public function testThePulseSaysNothingAboutAWindowNothingHappenedIn(): void
    {
        $area = $this->aRegister();
        $now = self::now();

        // Nothing has happened since 11:32; the module says so rather than
        // reaching further back to find something to show.
        self::assertSame([], $this->pulse()->pulseFor($area, $now->modify('-10 minutes'), $now));
    }

    /**
     * THE MODULE'S WORDS INSIDE THE HOST'S SENTENCE. The host's line about its
     * operational plate used to say "open incidents" in the host's own copy,
     * which promised them to areas with no register. The phrase is the module's
     * now — a phrase, lower case and unpunctuated, because the sentence, the
     * conjunction and the full stop are the host's.
     */
    public function testTheModuleContributesItsPhraseAndNoSentence(): void
    {
        $copy = $this->service('incident.overview.copy');
        self::assertInstanceOf(IncidentOverviewCopy::class, $copy);

        self::assertSame(['open incidents'], $copy->copyFragments(OverviewCopyProviderInterface::SLOT_MAP_LAYERS));
        // What a map-led page is worth adopting for is a claim this module does
        // not make — silence is an answer, not a gap.
        self::assertSame([], $copy->copyFragments(OverviewCopyProviderInterface::SLOT_MAP_GROUND_SPOTTING));

        foreach ($copy->copyFragments(OverviewCopyProviderInterface::SLOT_MAP_LAYERS) as $phrase) {
            self::assertSame(mb_strtolower($phrase), $phrase, $phrase.' is capitalised — the host decides where the sentence starts.');
            self::assertStringEndsNotWith('.', $phrase);
        }
    }

    /** class => the service id the bundle registers it under. */
    private const array SERVICES = [
        IncidentOverviewContributor::class => 'incident.overview.contributor',
        IncidentNowTiles::class => 'incident.overview.now_tiles',
        IncidentAttention::class => 'incident.overview.attention',
        IncidentMapLayers::class => 'incident.overview.map_layers',
        IncidentPulse::class => 'incident.overview.pulse',
        IncidentOverviewCopy::class => 'incident.overview.copy',
    ];

    private function contributor(): IncidentOverviewContributor
    {
        $service = $this->service('incident.overview.contributor');
        self::assertInstanceOf(IncidentOverviewContributor::class, $service);

        return $service;
    }

    private function tiles(): IncidentNowTiles
    {
        $service = $this->service('incident.overview.now_tiles');
        self::assertInstanceOf(IncidentNowTiles::class, $service);

        return $service;
    }

    private function attention(): IncidentAttention
    {
        $service = $this->service('incident.overview.attention');
        self::assertInstanceOf(IncidentAttention::class, $service);

        return $service;
    }

    private function layers(): IncidentMapLayers
    {
        $service = $this->service('incident.overview.map_layers');
        self::assertInstanceOf(IncidentMapLayers::class, $service);

        return $service;
    }

    private function pulse(): IncidentPulse
    {
        $service = $this->service('incident.overview.pulse');
        self::assertInstanceOf(IncidentPulse::class, $service);

        return $service;
    }
}
