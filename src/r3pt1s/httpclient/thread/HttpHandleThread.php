<?php

namespace r3pt1s\httpclient\thread;

use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;
use r3pt1s\httpclient\thread\misc\FinishedRequest;
use r3pt1s\httpclient\thread\misc\PendingRequest;

final class HttpHandleThread extends Thread {

    /** @var ThreadSafeArray<PendingRequest> */
    private ThreadSafeArray $pendingRequests;
    /** @var ThreadSafeArray<FinishedRequest> */
    private ThreadSafeArray $doneRequests;
    private SleeperHandlerEntry $entry;

    public function __construct() {
        $this->pendingRequests = new ThreadSafeArray();
        $this->doneRequests = new ThreadSafeArray();
    }

    public function setEntry(SleeperHandlerEntry $entry): void {
        $this->entry = $entry;
    }

    protected function onRun(): void {
        $curl = curl_init();
        $notifier = $this->entry->createNotifier();
        while (!$this->isKilled) {
            $this->synchronized(function (): void {
                if (!$this->isKilled && $this->pendingRequests->count() == 0) $this->wait();
            });

            /** @var PendingRequest $request */
            if (($request = $this->pendingRequests->shift()) !== null) {
                curl_reset($curl);
                $response = $request->execute($curl);
                $this->doneRequests[] = $response;
                $notifier->wakeupSleeper();
            }
        }

        curl_close($curl);
    }

    public function enqueue(PendingRequest $pendingRequest): void {
        $this->synchronized(function () use($pendingRequest): void {
            $this->pendingRequests[] = $pendingRequest;
            $this->notify();
        });
    }

    public function getPendingRequests(): ThreadSafeArray {
        return $this->pendingRequests;
    }

    public function getDoneRequests(): ThreadSafeArray {
        return $this->doneRequests;
    }

    public function getEntry(): SleeperHandlerEntry {
        return $this->entry;
    }
}