<?php

namespace r3pt1s\httpclient\util;

final readonly class Address {

    public function __construct(
        private string $address,
        private int $port
    ) {}

    public function address(): string {
        return $this->address;
    }

    public function port(): int {
        return $this->port;
    }

    public function __toString(): string {
        return $this->address . ":" . $this->port;
    }

    public static function create(string $address, int $port): self {
        return new self($address, $port);
    }
}