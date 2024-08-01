<?php
declare(strict_types=1);

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class RoboFile extends \Robo\Tasks
{
    /**
     * @description Reinitializes this directory as a project repository and disassociates it from skeleton repository
     *
     * @param string $gitRepositoryUrl Git remote repository URL (e.g. git@github.com:vision-group-ag/foobar.git)
     */
    public function skeletonInit(string $gitRepositoryUrl): void
    {
        $repositoryUrlPattern = '#^(git@.*\.git|https?://.*)$#';

        if (!preg_match($repositoryUrlPattern, $gitRepositoryUrl)) {
            throw new InvalidArgumentException(sprintf("'%s' does not look like a valid git URL - should start with 'git@' or 'http(s)://'", $gitRepositoryUrl));
        }

        $this->_remove(__DIR__ . '/.git/');
        $this->say('Removed .git directory');

        $this->_exec('git init');
        $this->say('Initialized a new git repository');

        $this->_exec('git remote add origin ' . escapeshellarg($gitRepositoryUrl));
        $this->say("Added '{$gitRepositoryUrl}' as git remote");

        $this->_exec('x=$(cat .gitignore); echo "$x" | grep -v \'composer.lock\' > .gitignore ');
        $this->say("Removed composer.lock from .gitignore");

        $this->io()->success("Skeleton initialization completed successfully");
    }

    /**
     * @description Removes demo code from the project
     */
    public function demoRemove(): void
    {
        $this->_remove([
            __DIR__ . '/migrations/Version0000Demo.php',
            __DIR__ . '/src/Entity/DemoEntity.php',
            __DIR__ . '/src/Repository/DemoEntityRepository.php',
            __DIR__ . '/config/api-platform/DemoEntity.yaml',
            __DIR__ . '/config/doctrine/DemoEntity.orm.xml',
            __DIR__ . '/config/serialization/DemoEntity.yaml',
            __DIR__ . '/config/validator/DemoEntity.yaml',
            __DIR__ . '/tests/acceptance/features/demo.feature',
            __DIR__ . '/tests/api/DemoCest.php',
            __DIR__ . '/tests/Spec/Entity/DemoEntitySpec.php',
        ]);
        $this->say("Removed demo files");

        $this->_exec(sprintf('echo "# %s\n\nProject setup to be documented here" > README.md', basename(__DIR__)));
        $this->say('Reset contents of README.md');

        $this->_exec('bin/console cache:clear');
        $this->say("Cleared Symfony cache");

        $this->io()->success("Removed demo files successfully");
    }
}
