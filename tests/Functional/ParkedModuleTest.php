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

namespace Uhifadhi\Incident\Tests\Functional;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Incident\Module\IncidentModuleProvider;

/**
 * WHERE AN AREA IS NOT RUNNING THIS MODULE, EVERY PAGE IT SHIPS IS GONE.
 *
 * The module writes no check for this and cannot forget it: RegistryBundle owns
 * the per-area ledger, so RegistryBundle enforces it, in one `kernel.request`
 * listener that runs after the router and before any controller. What this suite
 * has to prove is that the listener can SEE these routes — which is a claim about
 * this module, not about the core.
 *
 * IT IS 404, NOT 403, and the difference is the product's. A 403 confirms the
 * page exists and is being kept from the caller: true about a permission, false
 * about parking. A parked module is not withheld; the area is not running it,
 * which is what the area's own screens already say with the module sitting in
 * the shop rather than the sub-nav.
 *
 * WHY IT IS NOT ENOUGH THAT THE URLS HAPPEN TO FIT. The gate recognises a
 * request in one of two ways: the route says which module it belongs to, or the
 * PATH is on the fleet's `/areas/{uuid}/modules/{slug}/…` shape AND the segment
 * names a module in the catalogue. Every incident URL happens to satisfy the
 * second, because the slug and the segment are both `incidents` — an accident,
 * not a contract, and one that would end the moment a route moved or a slug was
 * renamed. The routes carry the marker, and these are the tests that would fail
 * if somebody dropped it.
 *
 * @see \Uhifadhi\Bundle\RegistryBundle\EventListener\ParkedModuleListener
 */
final class ParkedModuleTest extends FunctionalTestCase
{
    /**
     * An area that has never taken this module — which is the state every area
     * is in until an admin says otherwise, and the state a parked one returns to.
     */
    public function testAnAreaNotRunningTheModuleAnswers404(): void
    {
        $area = $this->anAreaWithoutTheModule('Southern Reserve');

        $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * And an area that IS running it, switched on the way an admin switches it
     * on — through the registry's own service, against the catalogue row the
     * registry sync wrote from this module's provider.
     */
    public function testAnAreaRunningTheModuleAnswers200(): void
    {
        $area = $this->anArea();

        $this->client->loginUser($this->aReporter());
        $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
    }

    /**
     * THE LEDGER ROW IS REAL, and this is what makes the test above mean
     * anything: `install()` answers null for a slug the catalogue does not
     * carry, so a suite whose registry was never synced would install nothing,
     * assert 200 off an empty catalogue, and prove only that the gate stays out
     * of the way when it knows nothing.
     */
    public function testTheFixtureInstallsThroughTheRegistrysOwnService(): void
    {
        $area = $this->anArea();

        /** @var AreaModuleService $areaModules */
        $areaModules = static::getContainer()->get('test_public.registry.area_modules');

        self::assertNotNull(
            $areaModules->install($area, IncidentModuleProvider::SLUG),
            'The catalogue has to carry the incidents row before an area can be given it.',
        );
    }

    /**
     * EVERY ROUTE SAYS WHICH MODULE IT BELONGS TO. Without the marker a route is
     * not exempt, it is guessed at — and a guess that currently lands is a guess
     * all the same. One class-level default per controller, spelled from the
     * constant the registry publishes.
     */
    public function testEveryIncidentRouteDeclaresTheModuleItBelongsTo(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $unmarked = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with((string) $name, 'incident_')) {
                continue;
            }

            if (IncidentModuleProvider::SLUG !== ($route->getDefaults()[RegistryBundle::MODULE_ROUTE_DEFAULT] ?? null)) {
                $unmarked[] = $name;
            }
        }

        sort($unmarked);

        self::assertSame([], $unmarked, \sprintf(
            'These routes do not say they belong to "%s", so the registry can only guess at them from their path: %s',
            IncidentModuleProvider::SLUG,
            implode(', ', $unmarked),
        ));
    }
}
