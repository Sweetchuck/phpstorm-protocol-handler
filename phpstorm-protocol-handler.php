#!/usr/bin/env php
<?php

declare(strict_types = 1);

class Handler {

    /**
     * @var string
     */
    protected $protocol = 'phpstorm';

    /**
     * @var string[]
     */
    protected $supportedActions = [
        'open',
    ];

    /**
     * @var array
     */
    protected $input = [];

    public function doIt(string $url)
    {
        $this->input = $this->parseInputUrl($url);
        $this->validateInput();

        switch ($this->input['host']) {
            case 'open':
                $this->actionOpen();
        }

        return $this;
    }

    protected function parseInputUrl(string $url): array
    {
        $input = parse_url($url);
        $input += [
            'scheme' => '',
            'host' => '',
            'query' => '',
        ];

        if ($input['query']) {
            parse_str($input['query'], $input['query']);
        }

        return $input;
    }

    protected function validateInput()
    {
        // @todo ext-pcntl
        // @todo ext-mbstring
        if ($this->input['scheme'] !== $this->protocol) {
            throw new \InvalidArgumentException(
                sprintf(
                "not supported protocol; expected: phpstorm; actual: %s;",
                    $this->input['scheme'],
                ),
                2,
            );
        }

        if (!in_array($this->input['host'], $this->supportedActions)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "not supported action; expected is one of: %s; actual: %s;" . PHP_EOL,
                    implode(', ', $this->supportedActions),
                    $this->input['host'],
                ),
                3,
            );
        }

        return $this;
    }

    protected function actionOpen()
    {
        $args = $this->actionOpenParseArguments();
        $this->actionOpenValidate($args);

        $windowsBefore = $this->getWindowList();
        if ($this->isProjectRoot($args['file'])) {
            $command = $this->actionOpenBuildCommand($args);
            $this->forkExec($command);
            sleep(2);
        } else {
            $projectRoot = $this->findFileUpward('.idea', $args['file']);
            if ($projectRoot) {
                $command = $this->actionOpenBuildCommand(['file' => $projectRoot]);
                $this->forkExec($command);
                sleep(2);
            }

            $command = $this->actionOpenBuildCommand($args);
            $this->forkExec($command);
            if (!$projectRoot) {
                sleep(2);
            }
        }

        $window = $this->selectWindowByFileName(
            $args['file'],
            $windowsBefore,
            $this->getWindowList(),
        );
        if ($window) {
            $this->activateWindow($window);
        }

        return $this;
    }

    protected function actionOpenParseArguments(): array
    {
        $args = [
            'file' => '',
            'line' => '',
            'column' => '',
        ];

        $args['file'] = (string) ($this->input['query']['url'] ?? $this->input['query']['file'] ?? '');

        $args['line'] = (string) ($this->input['query']['line'] ?? '');
        $args['line'] = trim($args['line'], ':');
        $args['column'] = (string) ($this->input['query']['column'] ?? '');
        if ($args['column'] === '' && mb_strpos($args['line'], ':')) {
            [$args['line'], $args['column']] = explode(':', $args['line'], 2);
        }

        return $args;
    }

    protected function actionOpenValidate(array $args)
    {
        if ($args['file'] === '') {
            throw new \InvalidArgumentException(
                sprintf(
                    'required parameter is missing: %s',
                    'file',
                ),
                4
            );
        }

        return $this;
    }

    protected function actionOpenBuildCommand(array $args): string
    {
        $args += [
            'file' => '',
            'line' => '',
            'column' => '',
        ];

        $command = [
            '/usr/bin/env',
            'phpstorm',
        ];
        if ($args['line'] !== '') {
            $command[] = '--line';
            $command[] = escapeshellarg($args['line']);
            if ($args['column'] !== '') {
                $command[] = '--column';
                $command[] = escapeshellarg($args['column']);
            }
        }
        $command[] = escapeshellarg($args['file']);
        $command[] = '1>/dev/null';
        $command[] = '2>/dev/null';

        return implode(' ', $command);
    }

    protected function getWindowList(): array
    {
        $command = 'wmctrl -l';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode) {
            return [];
        }

        $windows = [];
        $pattern = '/\s+/u';
        $keys = [
            'windowId',
            'desktopId',
            'clientMachine',
            'windowTitle',
        ];
        foreach ($output as $line) {
            $line = trim($line);
            if (!$line) {
                continue;
            }

            $parts = array_combine(
                $keys,
                preg_split($pattern, $line, 4),
            );
            $windows[$parts['windowId']] = $parts;
        }

        return $windows;
    }

    protected function activateWindow(array $window)
    {
        $command = sprintf(
            'wmctrl -i -a %s',
            escapeshellarg($window['windowId']),
        );
        exec($command);

        return $this;
    }

    protected function forkExec(string $command)
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException(
                sprintf(
                    'process fork failed for command: %s',
                    $command,
                ),
                5,
            );
        }

        if ($pid === 0) {
            posix_setsid();
            exec($command);
        }

        return $this;
    }

    protected function selectWindowByFileName(string $fileName, array $before, array $after): ?array
    {
        $window = $this->findWindowByFileName(
            $fileName,
            array_reverse(array_diff_key($after, $before), true),
        );

        return $window ?: $this->findWindowByFileName($fileName, $after);
    }

    protected function findWindowByFileName(string $fileName, array $windows): ?array
    {
        $baseName = basename($fileName);
        $needles = [
            "$baseName – $fileName",
            "$baseName – $baseName",
            " – $fileName",
            " – $baseName",
        ];

        foreach ($needles as $needle) {
            foreach ($windows as $window) {
                if (mb_strpos($window['windowTitle'], $needle) !== false) {
                    return $window;
                }
            }
        }

        return null;
    }

    /**
     * @param string $fileName
     * @param string $currentDir
     * @param null|string $rootDir
     *   Do not go above this directory.
     *
     * @return null|string
     *   Returns NULL if the $fileName not exists in any of the parent directories,
     *   returns the parent directory without the $fileName if the $fileName
     *   exists in one of the parent directory.
     */
    protected function findFileUpward(
        string $fileName,
        string $currentDir,
        ?string $rootDir = null
    ): ?string {
        if ($rootDir !== null && !$this->isParentDirOrSame($rootDir, $currentDir)) {
            throw new \InvalidArgumentException("The '$rootDir' is not parent dir of '$currentDir'");
        }

        while ($currentDir && ($rootDir === null || $this->isParentDirOrSame($rootDir, $currentDir))) {
            if (file_exists("$currentDir/$fileName")) {
                return $currentDir;
            }

            $parentDir = dirname($currentDir);
            if ($currentDir === $parentDir) {
                break;
            }

            $currentDir = $parentDir;
        }

        return null;
    }

    protected function isParentDirOrSame(string $parentDir, string $childDir): bool
    {
        $pattern = '@^' . preg_quote($parentDir, '@') . '(/|$)@';

        return (bool) preg_match($pattern, $childDir);
    }

    protected function isProjectRoot(string $fileName): bool
    {
        return is_dir("$fileName/.idea");
    }
}

$stdError = \STDERR;

$exitCode = 0;
try {
    $handler = new Handler();
    $handler->doIt($argv[1] ?? '');
} catch (\Throwable $e) {
    fwrite($stdError, $e->getMessage());
    $exitCode = $e->getCode() ?: 1;
}

exit($exitCode);
