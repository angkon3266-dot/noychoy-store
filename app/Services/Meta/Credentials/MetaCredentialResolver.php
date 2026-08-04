<?php

namespace App\Services\Meta\Credentials;

/**
 * Resolves the Meta credentials that the current request or job should use.
 *
 * This interface exists so that "whose credentials are these?" becomes an
 * explicit, injectable decision instead of an assumption baked into every
 * service. Today exactly one implementation exists
 * ({@see SingleStoreCredentialResolver}) and it answers "the one store on this
 * install" — which is the current, correct behaviour.
 *
 * When multi-tenancy arrives, a `PerMerchantCredentialResolver` is added and
 * bound in its place. Nothing that depends on this interface has to change,
 * because none of those consumers ever knew where the credentials came from.
 *
 * The rule to preserve: **never resolve credentials from a global inside a
 * service.** Take this contract, ask it, and let the binding decide. See
 * docs/META-MULTITENANCY.md for the full list of what changes.
 */
interface MetaCredentialResolver
{
    /**
     * Credentials for the store this request/job belongs to.
     *
     * Returns {@see MetaCredentials::none()} rather than null when nothing is
     * connected, so callers never have to null-check before asking a question.
     */
    public function resolve(): MetaCredentials;

    /**
     * A stable identifier for whichever store `resolve()` just answered for.
     *
     * Today this is always 'default'. It exists now so that queue jobs, cache
     * keys and log lines can be written tenant-shaped from the start — a job
     * that serialises this key keeps working unchanged when the key starts
     * meaning a real merchant id.
     */
    public function currentKey(): string;
}
