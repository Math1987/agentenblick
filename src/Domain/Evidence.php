<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Domain;

enum Evidence: string
{
    case Signature = 'verified_signature';
    case Ip = 'verified_ip';
    case Rdns = 'verified_rdns';
    case Declared = 'declared_unverified';
    case None = 'none';
}
