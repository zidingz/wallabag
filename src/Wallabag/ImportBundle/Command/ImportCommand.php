<?php

namespace Wallabag\ImportBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Wallabag\ImportBundle\Import\ImportChain;
use Wallabag\UserBundle\Repository\UserRepository;

class ImportCommand extends Command
{
    private $entityManager;
    private $tokenStorage;
    private $userRepository;
    private $importChain;

    public function __construct(EntityManagerInterface $entityManager, TokenStorageInterface $tokenStorage, UserRepository $userRepository, ImportChain $importChain)
    {
        $this->entityManager = $entityManager;
        $this->tokenStorage = $tokenStorage;
        $this->userRepository = $userRepository;
        $this->importChain = $importChain;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('wallabag:import')
            ->setDescription('Import entries from a JSON export')
            ->addArgument('username', InputArgument::REQUIRED, 'User to populate')
            ->addArgument('filepath', InputArgument::REQUIRED, 'Path to the JSON file')
            ->addOption('importer', null, InputOption::VALUE_OPTIONAL, 'The importer to use: v1, v2, instapaper, pinboard, readability, firefox or chrome', 'v1')
            ->addOption('markAsRead', null, InputOption::VALUE_OPTIONAL, 'Mark all entries as read', false)
            ->addOption('useUserId', null, InputOption::VALUE_NONE, 'Use user id instead of username to find account')
            ->addOption('disableContentUpdate', null, InputOption::VALUE_NONE, 'Disable fetching updated content from URL')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Start : ' . (new \DateTime())->format('d-m-Y G:i:s') . ' ---');

        if (!file_exists($input->getArgument('filepath'))) {
            throw new Exception(sprintf('File "%s" not found', $input->getArgument('filepath')));
        }

        // Turning off doctrine default logs queries for saving memory
        $this->entityManager->getConnection()->getConfiguration()->setSQLLogger(null);

        if ($input->getOption('useUserId')) {
            $entityUser = $this->userRepository->findOneById($input->getArgument('username'));
        } else {
            $entityUser = $this->userRepository->findOneByUsername($input->getArgument('username'));
        }

        if (!\is_object($entityUser)) {
            throw new Exception(sprintf('User "%s" not found', $input->getArgument('username')));
        }

        // Authenticate user for paywalled websites
        $token = new UsernamePasswordToken(
            $entityUser,
            null,
            'main',
            $entityUser->getRoles());

        $this->tokenStorage->setToken($token);
        $user = $this->tokenStorage->getToken()->getUser();

        switch ($input->getOption('importer')) {
            case 'v2':
                $import = $this->importChain->get('wallabag_v2');
                break;
            case 'firefox':
                $import = $this->importChain->get('firefox');
                break;
            case 'chrome':
                $import = $this->importChain->get('chrome');
                break;
            case 'readability':
                $import = $this->importChain->get('readability');
                break;
            case 'instapaper':
                $import = $this->importChain->get('instapaper');
                break;
            case 'pinboard':
                $import = $this->importChain->get('pinboard');
                break;
            default:
                $import = $this->importChain->get('wallabag_v1');
        }

        $import->setMarkAsRead($input->getOption('markAsRead'));
        $import->setDisableContentUpdate($input->getOption('disableContentUpdate'));
        $import->setUser($user);

        $res = $import
            ->setFilepath($input->getArgument('filepath'))
            ->import();

        if (true === $res) {
            $summary = $import->getSummary();
            $output->writeln('<info>' . $summary['imported'] . ' imported</info>');
            $output->writeln('<comment>' . $summary['skipped'] . ' already saved</comment>');
        }

        $this->entityManager->clear();

        $output->writeln('End : ' . (new \DateTime())->format('d-m-Y G:i:s') . ' ---');
    }
}
