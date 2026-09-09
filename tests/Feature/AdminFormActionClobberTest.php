<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A form exposes its own fields as properties, and a field SHADOWS the
 * attribute it collides with. One `<input name="action">` and `form.action`
 * stops being the URL and becomes that input element.
 *
 * The abandoned-cart bulk bar shipped with exactly that. `admin-ajax.js` did
 * `fetch(form.action)`, the browser stringified the element, and every bulk
 * button posted to `/admin/[object HTMLInputElement]` — a 404 the owner
 * reported as "deleting gives me a 404", pointing nowhere near the cause.
 * Server-side tests all passed, because the request never reached the server.
 *
 * Two things keep it fixed, and this pins both: the ajax layer reads the
 * attribute rather than the property, and no intercepted form carries a field
 * named after one of the attributes it needs.
 */
class AdminFormActionClobberTest extends TestCase
{
    /** Properties admin-ajax.js reads off a form before submitting it. */
    private const CLOBBERABLE = ['action', 'method', 'target', 'download'];

    /**
     * Forms allowed to carry such a field, and why.
     *
     * resources/views/admin/products/index.blade.php submits through
     * `this.$refs.bulk.submit()`. form.submit() fires no submit event, so the
     * ajax layer never sees it and the browser uses the real action attribute.
     * It is safe today, and listed here so that changing it to requestSubmit()
     * has to come past this test.
     */
    private const ALLOWED = ['admin/products/index.blade.php'];

    public function test_the_ajax_layer_reads_the_attribute_not_the_clobberable_property(): void
    {
        $source = file_get_contents(resource_path('js/admin-ajax.js'));

        // Comments stripped: the file explains the trap by naming form.action,
        // and a test that cannot tell the explanation from the mistake is a
        // test that punishes anyone for documenting it.
        $this->assertDoesNotMatchRegularExpression(
            '/\bform\.(?:'.implode('|', self::CLOBBERABLE).')\b/',
            $this->withoutComments($source),
            'these form properties are shadowed by a field of the same name — use getAttribute()',
        );
        $this->assertStringContainsString('form.getAttribute(name)', $source,
            'the attribute reader is how every one of those is meant to be read');
    }

    /** The source with its comments removed, so prose cannot fail a code check. */
    private function withoutComments(string $source): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
    }

    /**
     * A submit button overrides the form with formaction/formmethod/formtarget,
     * and every "Test connection" button on the config screens is exactly that:
     * one form, a second button pointing at the test route. The ajax layer read
     * the form alone, so those clicks posted to save instead, failed the save
     * form's validation, and looked like a button that did nothing.
     */
    public function test_the_ajax_layer_lets_the_clicked_button_redirect_the_submission(): void
    {
        $source = file_get_contents(resource_path('js/admin-ajax.js'));

        $this->assertStringContainsString("getAttribute('form' + name)", $source,
            'formaction/formmethod/formtarget on the submitter have to win over the form');
        $this->assertStringContainsString("submitAttr(form, submitter, 'action')", $source,
            'the POST must go where the clicked button aims it');
        $this->assertStringContainsString('new FormData(form, submitter)', $source,
            "a plain FormData(form) drops the clicked button's own name and value");
    }

    /** Every formaction button lives on a form the layer above must respect. */
    public function test_every_formaction_button_is_on_an_ajax_intercepted_form(): void
    {
        $found = 0;

        foreach ($this->adminViews() as $relative => $contents) {
            foreach ($this->postForms($contents) as $form) {
                $found += preg_match_all('/\bformaction=/i', $form);
            }
        }

        $this->assertGreaterThan(0, $found,
            'no formaction button was found — either they moved, or postForms() stopped matching');
    }

    public function test_no_ajax_intercepted_admin_form_shadows_an_attribute_it_needs(): void
    {
        $offenders = [];

        foreach ($this->adminViews() as $relative => $contents) {
            foreach ($this->postForms($contents) as $form) {
                foreach (self::CLOBBERABLE as $name) {
                    if (preg_match('/<(?:input|select|textarea|button)\b[^>]*\bname=["\']'.$name.'["\']/i', $form)) {
                        $offenders[] = "{$relative} — a field named \"{$name}\"";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These POST forms are intercepted by admin-ajax.js and carry a field whose'],
            ['name shadows a form property it reads. Rename the field (e.g. "bulk_action"):'],
            $offenders,
        )));
    }

    /** Every Blade file under resources/views/admin, keyed by a readable path. */
    private function adminViews(): array
    {
        $root = resource_path('views/admin');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $views = [];

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(resource_path('views')) + 1));

            if (! in_array($relative, self::ALLOWED, true)) {
                $views[$relative] = file_get_contents($file->getPathname());
            }
        }

        $this->assertNotEmpty($views, 'no admin views were scanned — the path is wrong');

        return $views;
    }

    /** The POST forms in one view that admin-ajax.js would intercept. */
    private function postForms(string $contents): array
    {
        if (! preg_match_all('/<form\b(.*?)<\/form>/is', $contents, $matches)) {
            return [];
        }

        return array_values(array_filter($matches[0], function (string $form) {
            $open = substr($form, 0, strpos($form, '>') ?: strlen($form));

            // eligible() in admin-ajax.js: POST, and not opted out.
            return preg_match('/\bmethod=["\']post["\']/i', $open)
                && ! str_contains($open, 'data-no-ajax');
        }));
    }
}
