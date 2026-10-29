<?php

namespace Resofire\Picks\Service;

use RuntimeException;

/** CFBD said 429 a while ago and the pause is not over: nothing was asked. */
class CfbdPausedException extends RuntimeException
{
}
