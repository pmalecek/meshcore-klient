<?php
declare(strict_types=1);

namespace MeshClient;

/** Chyba volání meshcore-serveru; getCode() = HTTP stav (0 = server nedostupný). */
final class McServerException extends \RuntimeException
{
}
