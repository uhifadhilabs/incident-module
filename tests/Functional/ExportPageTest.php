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

/**
 * THE REGISTER, EXPORTED. The dashboard's Export action streams the same rows the
 * register lists, as CSV, respecting the one filter — so the file a person
 * downloads is exactly the register they were looking at.
 *
 * The export declares no permission of its own: reading the register is reading
 * the module, and the module deliberately ships no "view" gate (see
 * IncidentModuleProvider). So the export is offered on the same terms as the
 * dashboard — whoever can reach the one can download the other — and these tests
 * pin that alongside the filter behaviour.
 */
final class ExportPageTest extends FunctionalTestCase
{
    /**
     * THE FILE IS A CSV, and says so: text/csv, an attachment, a .csv name — the
     * three things that make a browser save it rather than render it.
     */
    public function testTheExportStreamsTheRegisterAsACsvAttachment(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $csv = $this->fetchCsv(\sprintf('/areas/%s/modules/incidents/export.csv', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('.csv', $disposition);

        // The header row names the register's columns.
        $lines = $this->rows($csv);
        self::assertContains('reference', $lines[0]);
        self::assertContains('category', $lines[0]);
        self::assertContains('status', $lines[0]);
        self::assertContains('severity', $lines[0]);
        self::assertContains('zone', $lines[0]);
        self::assertContains('assignee', $lines[0]);
    }

    /**
     * A FILED INCIDENT IS A ROW — its reference, category and status, the way the
     * register would show them.
     */
    public function testAFiledIncidentAppearsAsARow(): void
    {
        $area = $this->anArea();
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge');
        $this->client->loginUser($this->aReporter());

        $csv = $this->fetchCsv(\sprintf('/areas/%s/modules/incidents/export.csv', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        // Header plus exactly one data row.
        self::assertCount(2, $this->rows($csv));
        self::assertStringContainsString('Snare line lifted', $csv);
        // The reference the register prints, in its own column.
        self::assertMatchesRegularExpression('/[A-Z]{2,6}-\d{2,8}/', $csv);
    }

    /**
     * ONE FILTER, ON THE PAGE AND IN THE FILE. Narrowing to a category narrows the
     * export exactly as it narrows the register — same query, read as a file.
     */
    public function testTheExportRespectsTheCategoryFilter(): void
    {
        $area = $this->anArea();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside');
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge');
        $this->client->loginUser($this->aReporter());

        $whole = $this->fetchCsv(\sprintf('/areas/%s/modules/incidents/export.csv', $this->uuidOf($area)));
        self::assertCount(3, $this->rows($whole)); // header + two

        $poaching = $this->fetchCsv(\sprintf('/areas/%s/modules/incidents/export.csv?category=poaching', $this->uuidOf($area)));
        self::assertCount(2, $this->rows($poaching)); // header + one
        self::assertStringContainsString('Snare line', $poaching);
        self::assertStringNotContainsString('Lion killed four goats', $poaching);
    }

    /**
     * THE SEARCH BOX DRIVES THE FILE TOO. A word in the query narrows the export
     * the way it narrows the register.
     */
    public function testTheExportRespectsTheSearchQuery(): void
    {
        $area = $this->anArea();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside');
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge');
        $this->client->loginUser($this->aReporter());

        $narrowed = $this->fetchCsv(\sprintf('/areas/%s/modules/incidents/export.csv?q=goats', $this->uuidOf($area)));

        self::assertCount(2, $this->rows($narrowed)); // header + one
        self::assertStringContainsString('goats', $narrowed);
        self::assertStringNotContainsString('Snare line', $narrowed);
    }

    /**
     * THE READ IS THE MODULE'S, NOT A PERMISSION'S. A reporter holds neither the
     * manage tier nor anything a "view" gate would check, and still gets the file —
     * because the export is the register, and the register is the module. This is
     * the charter, pinned: nothing here can hide a row from anybody who can reach
     * the dashboard.
     */
    public function testTheExportIsReadableByAnyoneWhoCanReachTheDashboard(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        // A signed-in person holding neither incidents.record nor incidents.manage.
        $this->client->loginUser($this->aUser('bystander@example.test', 'Neema', 'Kimaro'));

        $csv = $this->fetchCsv(\sprintf('/areas/%s/modules/incidents/export.csv', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertContains('reference', $this->rows($csv)[0]);
    }

    /**
     * Stream the export and hand back its body. A StreamedResponse writes on
     * sendContent(), so the body is captured off the output buffer rather than
     * read from getContent() (which is false for a stream).
     */
    private function fetchCsv(string $url): string
    {
        // A StreamedResponse's getContent() is false, but the test client captures
        // what the stream sent while handling the request and hands it back on the
        // internal (BrowserKit) response — which is where the body is read from.
        $this->client->request('GET', $url);

        return (string) $this->client->getInternalResponse()->getContent();
    }

    /**
     * The CSV parsed back into rows of cells, so a test asserts on columns rather
     * than on a substring of one long string.
     *
     * @return list<list<string|null>>
     */
    private function rows(string $csv): array
    {
        $rows = [];
        foreach (explode("\n", trim($csv)) as $line) {
            if ('' === $line) {
                continue;
            }
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }
}
