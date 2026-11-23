<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog\Classifier;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionFailure;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProblem;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ProblemClassifier;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Language;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\ClassifierSearchItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\OfferType;
use RuntimeException;
use Throwable;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The classifier codes the tax service accepts, as VCR knows them.
 *
 * The store-wide classifier code used to be a free-text field, and a merchant
 * typed "47" — a NACE division, not a code — which nothing checked until their
 * first order was refused. Every code the plugin stores now comes from here:
 * the settings dropdown searches it, the save path checks against it, and the
 * checklist re-checks what an older version let through.
 *
 * VCR's search runs over exactly the set its sale validation accepts, goods and
 * activity codes alike (a product may carry either), so "the search returns
 * this exact code" is the same question as "a sale will accept it". Both
 * dictionaries are asked, because which one a code belongs to is not something
 * the plugin should guess from its shape.
 *
 * Not declared `final` so unit tests can mock it.
 */
class Directory
{
    private const TRANSIENT_KEY = 'vcr_classifier_verdicts';

    /** Codes change with a government order, not between page loads. */
    private const TTL_SECONDS = DAY_IN_SECONDS;

    /** How many near codes an "unknown" verdict offers instead. */
    private const SUGGESTION_LIMIT = 4;

    public function __construct(
        private readonly Configuration $config,
        private readonly SearcherFactory $searcherFactory,
        private readonly ProblemClassifier $classifier = new ProblemClassifier(),
    ) {
    }

    /**
     * Goods and activity matches, interleaved so neither dictionary crowds
     * the other out of the first lines: "47" matches ten goods codes before
     * the one activity code the merchant almost certainly meant.
     *
     * @return list<Entry>
     *
     * @throws Throwable When there is no API key or VCR cannot answer; the
     *                   caller decides how to say so.
     */
    public function search(string $query): array
    {
        $apiKey = $this->config->apiKey();
        if ($apiKey === null) {
            throw new RuntimeException('No VCR API key is saved.');
        }

        $searcher = $this->searcherFactory->create($apiKey);
        $language = $this->language();
        $goods = $searcher->search($query, OfferType::Product, $language);
        $activities = $searcher->search($query, OfferType::Service, $language);

        return $this->interleave($goods, $activities);
    }

    /** Never throws: a settings render must not be able to kill the page. */
    public function check(string $code): Verdict
    {
        $language = $this->language();
        $cacheKey = $language->value . ':' . $code;

        $cache = $this->readCache();
        $cached = $cache[$cacheKey] ?? null;
        if ($cached !== null) {
            return $this->fromCache($cached);
        }

        if ($this->config->apiKey() === null) {
            return Verdict::unavailable(new ConnectionFailure(ConnectionProblem::NoApiKey));
        }

        try {
            $matches = $this->search($code);
        } catch (Throwable $e) {
            // Not cached: a timeout says nothing about the code.
            return Verdict::unavailable($this->classifier->classify($e));
        }

        $verdict = $this->verdictFor($code, $matches);
        $cache[$cacheKey] = $this->toCache($verdict);
        set_transient(self::TRANSIENT_KEY, $cache, self::TTL_SECONDS);

        return $verdict;
    }

    public function refresh(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * @param list<Entry> $matches
     */
    private function verdictFor(string $code, array $matches): Verdict
    {
        foreach ($matches as $match) {
            if ($match->code === $code) {
                return Verdict::known($match);
            }
        }

        return Verdict::unknown(array_slice($matches, 0, self::SUGGESTION_LIMIT));
    }

    /**
     * @param  list<ClassifierSearchItem> $goods
     * @param  list<ClassifierSearchItem> $activities
     * @return list<Entry>
     */
    private function interleave(array $goods, array $activities): array
    {
        $entries = [];
        $seen = [];
        $longest = max(count($goods), count($activities));

        for ($i = 0; $i < $longest; $i++) {
            foreach ([$goods[$i] ?? null, $activities[$i] ?? null] as $item) {
                if ($item === null || isset($seen[$item->code])) {
                    continue;
                }

                $seen[$item->code] = true;
                $entries[] = new Entry($item->code, $item->title);
            }
        }

        return $entries;
    }

    /**
     * Names come back in the admin's own language when the classifier has
     * it: Armenian and Russian are what Armenian merchants read, and English
     * is the classifier's third language.
     */
    private function language(): Language
    {
        $locale = get_user_locale();

        if (str_starts_with($locale, 'hy')) {
            return Language::Armenian;
        }

        if (str_starts_with($locale, 'ru')) {
            return Language::Russian;
        }

        return Language::English;
    }

    /**
     * @return array<string, array{known: bool, code: string, title: ?string, suggestions: list<array{code: string, title: ?string}>}>
     */
    private function readCache(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);

        // The only writer is check() above, so the shape is ours.
        /** @var array<string, array{known: bool, code: string, title: ?string, suggestions: list<array{code: string, title: ?string}>}> */
        return is_array($cached) ? $cached : [];
    }

    /**
     * @return array{known: bool, code: string, title: ?string, suggestions: list<array{code: string, title: ?string}>}
     */
    private function toCache(Verdict $verdict): array
    {
        $suggestions = array_map(
            static fn (Entry $entry): array => ['code' => $entry->code, 'title' => $entry->title],
            $verdict->suggestions,
        );

        return [
            'known' => $verdict->entry !== null,
            'code' => $verdict->entry->code ?? '',
            'title' => $verdict->entry?->title,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * @param array{known: bool, code: string, title: ?string, suggestions: list<array{code: string, title: ?string}>} $cached
     */
    private function fromCache(array $cached): Verdict
    {
        if ($cached['known']) {
            return Verdict::known(new Entry($cached['code'], $cached['title']));
        }

        $suggestions = array_map(
            static fn (array $row): Entry => new Entry($row['code'], $row['title']),
            $cached['suggestions'],
        );

        return Verdict::unknown($suggestions);
    }
}
