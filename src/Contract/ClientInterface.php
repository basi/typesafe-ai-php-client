<?php

declare(strict_types=1);

namespace TypesafeAi\Contract;

use TypesafeAi\Exception\TypesafeAiException;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Response\ModelCard;
use TypesafeAi\Response\SystemOneResponse;

/**
 * A client for the typesafe.ai System One API.
 */
interface ClientInterface
{
    /**
     * Ask one or more questions about the content in the request's state.
     *
     * @throws TypesafeAiException
     */
    public function systemOne(SystemOneRequest $request): SystemOneResponse;

    /**
     * List the models and aliases available to the authenticated account.
     *
     * @return list<ModelCard>
     *
     * @throws TypesafeAiException
     */
    public function models(): array;
}
