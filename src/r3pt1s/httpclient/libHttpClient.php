<?php

namespace r3pt1s\httpclient;

use Closure;
use LogicException;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use r3pt1s\httpclient\io\ClientResponse;
use r3pt1s\httpclient\thread\HttpHandleThread;
use r3pt1s\httpclient\thread\misc\FinishedRequest;
use r3pt1s\httpclient\thread\misc\PendingRequest;
use r3pt1s\httpclient\util\RestAction;
use Throwable;

final class libHttpClient {

    private static ?self $instance = null;
    private static ?Plugin $registrant = null;

    public static function register(Plugin $registrant, int $threadCount = 0): void {
        if (self::$registrant !== null) {
            throw new LogicException("'libHttpClient' already has a registrant.");
        }

        self::$registrant = $registrant;
        self::$instance = new self($threadCount);
    }

    public static function release(): void {
        if (self::$registrant === null) {
            throw new LogicException("'libHttpClient' does not have a registrant.");
        }

        self::$instance->shutdown();
        self::$registrant = null;
        self::$instance = null;
    }

    /** @var array<HttpHandleThread> */
    private array $threads = [];
    private array $pendingRequests = [];

    /**
     * @param int $threadCount If 0, no threads will be created and the requests will remain synchronous, blocking the main thread from execution.
     */
    private function __construct(private readonly int $threadCount = 0) {
        if ($threadCount > 0) {
            for ($i = 0; $i < $threadCount; $i++) {
                $thread = new HttpHandleThread();
                $thread->setEntry(Server::getInstance()->getTickSleeper()->addNotifier(function () use($thread): void {
                    /** @var FinishedRequest $finishedRequest */
                    while (($finishedRequest = $thread->getDoneRequests()->shift()) !== null) {
                        $response = $finishedRequest->toClientResponse();
                        $id = $finishedRequest->requestId;
                        if (isset($this->pendingRequests[$id])) {
                            /** @var RestAction $action */
                            [$action, $successAndFailure] = $this->pendingRequests[$id];

                            foreach ($action->client()->afterActions() as $actionClosure) {
                                try {
                                    ($actionClosure)($action->context(), $response);
                                } catch (Throwable $e) {
                                    $response = $response->withException($e);
                                    break;
                                }
                            }

                            if ($successAndFailure !== null) ($successAndFailure)($response, $response->exception());
                            unset($this->pendingRequests[$id]);
                        }
                    }
                }));

                $thread->start();
                $this->threads[] = $thread;
            }
        }
    }

    /**
     * @param RestAction $action
     * @param Closure(ClientResponse $response, ?Throwable $e): void|null $successAndFailure
     * @return void
     * @internal
     */
    public function submitAsync(RestAction $action, ?Closure $successAndFailure): void {
        $thread = $this->selectThread();
        $thread->enqueue(PendingRequest::fromContext($requestId = uniqid("http-request-"), $action->context()));
        $this->pendingRequests[$requestId] = [$action, $successAndFailure];
    }

    protected function selectThread(): HttpHandleThread {
        $threads = $this->threads;
        if (count($threads) == 0) throw new LogicException("Tried to select a thread for a HTTP request but there are no threads running.");
        usort($threads, static fn(HttpHandleThread $a, HttpHandleThread $b) => $a->getPendingRequests()->count() <=> $b->getPendingRequests()->count());
        return $threads[0];
    }

    public function shutdown(): void {
        foreach ($this->threads as $thread) $thread->quit();
    }

    public function getThreadCount(): int {
        return $this->threadCount;
    }

    public function getThreads(): array {
        return $this->threads;
    }

    public static function getInstance(): ?self {
        return self::$instance;
    }
}