<?php

namespace Tests\Fakes;

use App\Services\Insurer\InsurerGateway;
use App\Services\Insurer\IssueRequest;
use App\Services\Insurer\IssueResult;
use App\Services\Insurer\LookupResult;
use Closure;
use LogicException;

/**
 * Test double that plays back a script of insurer behaviours and records
 * every call, so tests can assert exactly how often the insurer was hit.
 */
final class ScriptedInsurerGateway implements InsurerGateway
{
    /** @var list<array<string, mixed>> */
    public array $issueCalls = [];

    public int $lookupCalls = 0;

    /**
     * @param  list<Closure(IssueRequest): IssueResult>  $issueScript
     * @param  list<Closure(string): LookupResult>  $lookupScript
     */
    public function __construct(private array $issueScript = [], private array $lookupScript = [])
    {
    }

    public function issue(IssueRequest $request): IssueResult
    {
        $this->issueCalls[] = $request->toArray();
        $step = array_shift($this->issueScript) ?? throw new LogicException('Unexpected insurer issue() call.');

        return $step($request);
    }

    public function lookup(string $idempotencyKey): LookupResult
    {
        $this->lookupCalls++;
        $step = array_shift($this->lookupScript) ?? throw new LogicException('Unexpected insurer lookup() call.');

        return $step($idempotencyKey);
    }
}
