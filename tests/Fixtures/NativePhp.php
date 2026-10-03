<?php

declare(strict_types=1);

namespace Native\Mobile {
    use RuntimeException;

    final class NativeServiceProvider {}

    final class DeviceRoot
    {
        public int $getInfoCalls = 0;

        public int $getIdCalls = 0;

        public function __construct(
            public string $info = '{"model":"Phone","operatingSystem":"iOS","osVersion":"18.0","language":"en-US"}',
            public bool $throws = false,
        ) {}

        public function getId(): string
        {
            $this->getIdCalls++;

            return 'forbidden-device-id';
        }

        public function getInfo(): ?string
        {
            $this->getInfoCalls++;

            if ($this->throws) {
                throw new RuntimeException('device unavailable');
            }

            return $this->info;
        }
    }

    final class NetworkRoot
    {
        public function __construct(
            public ?object $status = null,
            public bool $throws = false,
        ) {}

        public function status(): ?object
        {
            if ($this->throws) {
                throw new RuntimeException('network unavailable');
            }

            return $this->status;
        }
    }
}

namespace Native\Mobile\Facades {
    use Illuminate\Support\Facades\Facade;

    final class Device extends Facade
    {
        protected static function getFacadeAccessor(): string
        {
            return 'nativephp.mobile.device';
        }
    }

    final class Network extends Facade
    {
        protected static function getFacadeAccessor(): string
        {
            return 'nativephp.mobile.network';
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

namespace Native\Desktop {
    use RuntimeException;

    final class NativeServiceProvider {}

    final class AppRoot
    {
        public function __construct(public bool $throws = false) {}

        public function version(): string
        {
            if ($this->throws) {
                throw new RuntimeException('app unavailable');
            }

            return '2.4.0';
        }

        public function getLocale(): string
        {
            if ($this->throws) {
                throw new RuntimeException('app unavailable');
            }

            return 'en-GB';
        }
    }

    final class ProcessRoot
    {
        public function platform(): string
        {
            return 'darwin';
        }

        public function arch(): string
        {
            return 'arm64';
        }
    }

    final class SystemRoot
    {
        public function timezone(): string
        {
            return 'Europe/London';
        }
    }
}

namespace Native\Desktop\Facades {
    use Illuminate\Support\Facades\Facade;

    final class App extends Facade
    {
        protected static function getFacadeAccessor(): string
        {
            return 'nativephp.desktop.app';
        }
    }

    final class Process extends Facade
    {
        protected static function getFacadeAccessor(): string
        {
            return 'nativephp.desktop.process';
        }
    }

    final class System extends Facade
    {
        protected static function getFacadeAccessor(): string
        {
            return 'nativephp.desktop.system';
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
        public function __construct(
            public string $version,
            public array $files,
            public string $releaseDate,
            public ?string $releaseName = null,
            public string|array|null $releaseNotes = null,
            public ?int $stagingPercentage = null,
            public ?string $minimumSystemVersion = null,
        ) {}
    }

    class UpdateDownloaded
    {
        public function __construct(
            public string $downloadedFile,
            public string $version,
            public array $files,
            public string $releaseDate,
            public ?string $releaseName = null,
            public string|array|null $releaseNotes = null,
            public ?int $stagingPercentage = null,
            public ?string $minimumSystemVersion = null,
        ) {}
    }
}
