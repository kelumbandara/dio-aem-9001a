<?php

namespace App\Modules\AI\Contracts;

use App\Modules\AI\DTOs\ProviderRequest;
use App\Modules\AI\DTOs\ProviderResponse;
use App\Modules\AI\Exceptions\AiException;

/** A language model behind one call. Implementations: ClaudeProvider (real), a scripted double in tests. */
interface AiProvider
{
    /** @throws AiException ai.not_configured, ai.provider_unavailable */
    public function complete(ProviderRequest $request): ProviderResponse;
}
