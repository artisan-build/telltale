<?php

declare(strict_types=1);

namespace Native\Mobile {
    final class NativeServiceProvider {}
}

namespace Native\Mobile\Facades {
    use RuntimeException;

    final class Device
    {
        public static string $info = '{"model":"Phone","operatingSystem":"iOS","osVersion":"18.0","language":"en-US"}';

        public static bool $throws = false;

        public static int $getInfoCalls = 0;

        public static int $getIdCalls = 0;

        public static function getId(): string
        {
            self::$getIdCalls++;

            return 'forbidden-device-id';
        }

        public static function getInfo(): ?string
        {
            self::$getInfoCalls++;

            if (self::$throws) {
                throw new RuntimeException('device unavailable');
            }

            return self::$info;
        }
    }

    final class Network
    {
        public static ?object $status = null;

        public static bool $throws = false;

        public static function status(): ?object
        {
            if (self::$throws) {
                throw new RuntimeException('network unavailable');
            }

            return self::$status;
        }
    }
}

namespace Native\Mobile\Events\Screen {
    class ScreenMounted
    {
        public function __construct(public string $component, public ?string $uri = null) {}
    }

    class ScreenResumed extends ScreenMounted {}

    class ScreenUnmounted extends ScreenMounted {}
}

namespace Native\Mobile\Events\App {
    final class UpdateInstalled
    {
        public function __construct(public readonly string $version, public readonly int $timestamp) {}
    }
}

namespace Native\Mobile\Events\Async {
    final class AsyncTaskFailed
    {
        public function __construct(
            public string $id,
            public string $exceptionClass,
            public string $message,
            public ?string $trace = null,
        ) {}
    }
}

namespace Native\Desktop {
    final class NativeServiceProvider {}
}

namespace Native\Desktop\Facades {
    use RuntimeException;

    final class App
    {
        public static bool $throws = false;

        public static function version(): string
        {
            if (self::$throws) {
                throw new RuntimeException('app unavailable');
            }

            return '2.4.0';
        }

        public static function getLocale(): string
        {
            return 'en-GB';
        }
    }

    final class Process
    {
        public static function platform(): string
        {
            return 'darwin';
        }

        public static function arch(): string
        {
            return 'arm64';
        }
    }

    final class System
    {
        public static function timezone(): string
        {
            return 'Europe/London';
        }
    }
}

namespace Native\Desktop\Events\Windows {
    class WindowFocused
    {
        public function __construct(public string $id) {}
    }

    class WindowBlurred extends WindowFocused {}
}

namespace Native\Desktop\Events\PowerMonitor {
    final class UserDidBecomeActive {}

    final class UserDidResignActive {}
}

namespace Native\Desktop\Events\AutoUpdater {
    class UpdateAvailable
    {
        public function __construct(public string $version) {}
    }

    class UpdateDownloaded extends UpdateAvailable {}
}
