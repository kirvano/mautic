<?php

namespace MauticPlugin\MauticSocialBundle\Tests\Integration;

use Mautic\CoreBundle\Translation\Translator;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Tests\Integration\AbstractIntegrationTestCase;
use MauticPlugin\MauticSocialBundle\Integration\FoursquareIntegration;
use PHPUnit\Framework\MockObject\MockObject;

class FoursquareIntegrationTest extends AbstractIntegrationTestCase
{
    private FoursquareIntegration $integration;

    /**
     * @var Translator&MockObject
     */
    protected $coreTranslator;

    /**
     * @var IntegrationHelper&MockObject
     */
    protected $integrationHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coreTranslator    = $this->createMock(Translator::class);
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);

        $this->integration = new FoursquareIntegration(
            $this->dispatcher,
            $this->cache,
            $this->em,
            $this->request,
            $this->router,
            $this->coreTranslator,
            $this->logger,
            $this->encryptionHelper,
            $this->leadModel,
            $this->companyModel,
            $this->pathsHelper,
            $this->notificationModel,
            $this->fieldModel,
            $this->fieldsWithUniqueIdentifier,
            $this->integrationEntityModel,
            $this->doNotContact,
            $this->integrationHelper,
        );
    }

    /**
     * @covers \MauticPlugin\MauticSocialBundle\Integration\FoursquareIntegration::getFormType
     */
    public function testGetFormTypeReturnsNull(): void
    {
        // @phpstan-ignore-next-line - Intentional null check
        $this->assertNull($this->integration->getFormType());
    }
}
