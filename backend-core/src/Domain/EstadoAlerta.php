<?php

declare(strict_types=1);

namespace Sippt\Domain;

enum EstadoAlerta: string
{
    case ABIERTA = 'abierta';
    case ATENDIDA = 'atendida';
}
