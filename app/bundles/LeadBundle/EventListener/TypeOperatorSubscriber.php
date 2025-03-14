<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\EventListener;

use DeviceDetector\Parser\Device\AbstractDeviceParser as DeviceParser;
use DeviceDetector\Parser\OperatingSystem;
use Mautic\AssetBundle\Model\AssetModel;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CategoryBundle\Model\CategoryModel;
use Mautic\CoreBundle\Form\Type\AlertType;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\OperatorListTrait;
use Mautic\LeadBundle\Event\FormAdjustmentEvent;
use Mautic\LeadBundle\Event\ListFieldChoicesEvent;
use Mautic\LeadBundle\Event\TypeOperatorsEvent;
use Mautic\LeadBundle\Form\Validator\Constraints\DbRegex;
use Mautic\LeadBundle\Helper\FormFieldHelper;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\LeadBundle\Segment\OperatorOptions;
use Mautic\StageBundle\Model\StageModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TypeOperatorSubscriber implements EventSubscriberInterface
{
    use OperatorListTrait;

    private const EMAIL_ALIAS = 'email';

    private TranslatorInterface $translator;

    public function __construct(
        private LeadModel $leadModel,
        private ListModel $listModel,
        private CampaignModel $campaignModel,
        private EmailModel $emailModel,
        private StageModel $stageModel,
        private CategoryModel $categoryModel,
        private AssetModel $assetModel,
        TranslatorInterface $translator
    ) {
        $this->translator    = $translator;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::COLLECT_OPERATORS_FOR_FIELD_TYPE           => ['onTypeOperatorsCollect', 0],
            LeadEvents::COLLECT_FILTER_CHOICES_FOR_LIST_FIELD_TYPE => ['onTypeListCollect', 0],
            LeadEvents::ADJUST_FILTER_FORM_TYPE_FOR_FIELD          => [
                ['onSegmentFilterFormHandleTags', 1000],
                ['onSegmentFilterFormHandleLookupId', 800],
                ['onSegmentFilterFormHandleLookup', 600],
                ['onSegmentFilterFormHandleSelect', 400],
                ['onSegmentFilterFormHandleDefault', 0],
            ],
        ];
    }

    public function onTypeOperatorsCollect(TypeOperatorsEvent $event): void
    {
        // Subscribe basic field types.
        foreach ($this->typeOperators as $typeName => $operatorOptions) {
            $event->setOperatorsForFieldType($typeName, $operatorOptions);
        }

        // Subscribe aliases
        $event->setOperatorsForFieldType('boolean', $this->typeOperators['bool']);
        $event->setOperatorsForFieldType('datetime', $this->typeOperators['date']);

        foreach (['country', 'timezone', 'region', 'locale'] as $selectAlias) {
            $event->setOperatorsForFieldType($selectAlias, $this->typeOperators['select']);
        }

        foreach (['lookup', 'text', 'email', 'url', 'tel'] as $textAlias) {
            $event->setOperatorsForFieldType($textAlias, $this->typeOperators['text']);
        }
    }

    public function onTypeListCollect(ListFieldChoicesEvent $event): void
    {
        $event->setChoicesForFieldType(
            'boolean',
            [
                $this->translator->trans('mautic.core.form.no')  => 0,
                $this->translator->trans('mautic.core.form.yes') => 1,
            ]
        );

        $emails = $this->emailModel->getLookupResults('email', '', 0, 0, ['name_is_key' => true]);

        $event->setChoicesForFieldAlias('lead_asset_download', $this->getAssetChoices($event->getSearchTerm()));
        $event->setChoicesForFieldAlias('campaign', $this->getCampaignChoices());
        $event->setChoicesForFieldAlias('leadlist', $this->getSegmentChoices());
        $event->setChoicesForFieldAlias('tags', $this->getTagChoices());
        $event->setChoicesForFieldAlias('stage', $this->getStageChoices());
        $event->setChoicesForFieldAlias('globalcategory', $this->getCategoryChoices());
        $event->setChoicesForFieldAlias('lead_email_received', $emails);
        $event->setChoicesForFieldAlias('lead_email_sent', $emails);
        $event->setChoicesForFieldAlias('device_type', array_combine(DeviceParser::getAvailableDeviceTypeNames(), DeviceParser::getAvailableDeviceTypeNames()));
        $event->setChoicesForFieldAlias('device_brand', array_flip(DeviceParser::$deviceBrands));
        $event->setChoicesForFieldAlias('device_os', array_combine(array_keys(OperatingSystem::getAvailableOperatingSystemFamilies()), array_keys(OperatingSystem::getAvailableOperatingSystemFamilies())));
        $event->setChoicesForFieldType('country', FormFieldHelper::getCountryChoices());
        $event->setChoicesForFieldType('locale', FormFieldHelper::getLocaleChoices());
        $event->setChoicesForFieldType('region', FormFieldHelper::getRegionChoices());
        $event->setChoicesForFieldType('timezone', FormFieldHelper::getTimezonesChoices());
    }

    public function onSegmentFilterFormHandleTags(FormAdjustmentEvent $event): void
    {
        if ('tags' !== $event->getFieldAlias()) {
            return;
        }

        $form = $event->getForm();

        $form->add(
            'filter',
            ChoiceType::class,
            [
                'label'                     => false,
                'data'                      => $form->getData()['filter'] ?? [],
                'choices'                   => FormFieldHelper::parseList($event->getFieldChoices()),
                'multiple'                  => true,
                'choice_translation_domain' => false,
                'disabled'                  => $event->filterShouldBeDisabled(),
                'constraints'               => $event->filterShouldBeDisabled() ? [] : [new NotBlank(['message' => 'mautic.core.value.required'])],
                'attr'                      => [
                    'class'                => 'form-control',
                    'data-placeholder'     => $this->translator->trans('mautic.lead.tags.select_or_create'),
                    'data-no-results-text' => $this->translator->trans('mautic.lead.tags.enter_to_create'),
                    'data-allow-add'       => true,
                    'onchange'             => 'Mautic.createLeadTag(this)',
                ],
            ]
        );
        $event->stopPropagation();
    }

    /**
     * For fields where users search by label but we need the ID. Example: owner.
     */
    public function onSegmentFilterFormHandleLookupId(FormAdjustmentEvent $event): void
    {
        if (!$event->fieldTypeIsOneOf('lookup_id') || !$event->operatorIsOneOf(OperatorOptions::EQUAL_TO, OperatorOptions::NOT_EQUAL_TO)) {
            return;
        }

        $form        = $event->getForm();
        $properties  = $event->getFieldDetails()['properties'] ?? [];
        $displayAttr = [
            'class'               => 'form-control',
            'data-field-callback' => $properties['callback'] ?? 'activateSegmentFilterTypeahead',
            'data-target'         => $event->getFieldAlias(),
            'placeholder'         => $this->translator->trans(
                'mautic.lead.list.form.startTyping'
            ),
            'data-no-record-message'=> $this->translator->trans(
                'mautic.core.form.nomatches'
            ),
        ];

        if (isset($properties['data-action'])) {
            $displayAttr['data-action'] = $properties['data-action'];
        }

        // This field will hold the label of the lookup item.
        $form->add(
            'display',
            TextType::class,
            [
                'label'       => false,
                'required'    => true,
                'data'        => $form->getData()['display'] ?? '',
                'attr'        => $displayAttr,
                'constraints' => [
                    new NotBlank(
                        ['message' => 'mautic.core.value.required']
                    ),
                ],
            ]
        );

        // This field will hold the ID of the lookup item.
        $form->add(
            'filter',
            HiddenType::class,
            [
                'label'       => false,
                'required'    => true,
                'data'        => $form->getData()['filter'] ?? '',
                'attr'        => ['class' => 'form-control'],
                'disabled'    => $event->filterShouldBeDisabled(),
                'constraints' => [
                    new NotBlank(
                        ['message' => 'mautic.core.value.required']
                    ),
                ],
            ]
        );
        $event->stopPropagation();
    }

    public function onSegmentFilterFormHandleLookup(FormAdjustmentEvent $event): void
    {
        if (!$event->fieldTypeIsOneOf('lookup')) {
            return;
        }

        $form = $event->getForm();

        $form->add(
            'filter',
            TextType::class,
            [
                'label'    => false,
                'disabled' => $event->filterShouldBeDisabled(),
                'data'     => $form->getData()['filter'] ?? '',
                'attr'     => [
                    'class'        => 'form-control',
                    'data-toggle'  => 'field-lookup',
                    'data-options' => $event->getFieldChoices(),
                    'data-target'  => $event->getFieldAlias(),
                    'data-action'  => 'lead:fieldList',
                    'placeholder'  => $this->translator->trans('mautic.lead.list.form.filtervalue'),
                ],
            ]
        );

        $event->stopPropagation();
    }

    public function onSegmentFilterFormHandleSelect(FormAdjustmentEvent $event): void
    {
        $form       = $event->getForm();
        $data       = $form->getData();
        $multiple   = $event->operatorIsOneOf(OperatorOptions::IN, OperatorOptions::NOT_IN) || $event->fieldTypeIsOneOf('multiselect');
        $mustBeText = $event->operatorIsOneOf(OperatorOptions::REGEXP, OperatorOptions::NOT_REGEXP, OperatorOptions::STARTS_WITH, OperatorOptions::ENDS_WITH, OperatorOptions::CONTAINS, OperatorOptions::LIKE, OperatorOptions::NOT_LIKE);
        $isSelect   = $event->fieldTypeIsOneOf('select', 'multiselect', 'boolean', 'country', 'locale', 'region', 'timezone', 'leadlist', 'campaign', 'device_type', 'device_brand', 'device_os', 'stage', 'globalcategory', 'assets', 'lead_email_received');

        if (!$mustBeText && $isSelect) {
            $filter = $data['filter'] ?? '';

            // Conversion between select and multiselect values.
            if ($multiple) {
                if (!isset($data['filter'])) {
                    $filter = [];
                } elseif (!is_array($data['filter'])) {
                    $filter = [$data['filter']];
                }
            }

            $form->add(
                'filter',
                ChoiceType::class,
                [
                    'label'                     => false,
                    'attr'                      => ['class' => 'form-control'],
                    'data'                      => $filter,
                    'choices'                   => $event->getFieldChoices(),
                    'multiple'                  => $multiple,
                    'choice_translation_domain' => false,
                    'disabled'                  => $event->filterShouldBeDisabled(),
                    'constraints'               => $event->filterShouldBeDisabled() ? [] : [new NotBlank(['message' => 'mautic.core.value.required'])],
                ]
            );
            $event->stopPropagation();
        }
    }

    public function onSegmentFilterFormHandleDefault(FormAdjustmentEvent $event): void
    {
        $form        = $event->getForm();
        $constraints = [];

        if (in_array($event->getOperator(), [OperatorOptions::REGEXP, OperatorOptions::NOT_REGEXP], true)) {
            $constraints[] = new DbRegex();
        }

        $form->add(
            'filter',
            TextType::class,
            [
                'label'       => false,
                'attr'        => ['class' => 'form-control'],
                'disabled'    => $event->filterShouldBeDisabled(),
                'data'        => $form->getData()['filter'] ?? '',
                'constraints' => $constraints,
            ]
        );
        $this->showOperatorsBasedAlertMessages($event);
        $event->stopPropagation();
    }

    private function showOperatorsBasedAlertMessages(FormAdjustmentEvent $event): void
    {
        switch ($event->getOperator()) {
            case OperatorOptions::REGEXP:
            case OperatorOptions::NOT_REGEXP:
                $alertText = $this->translator->trans('mautic.lead_list.filter.alert.regexp');
                break;
            case OperatorOptions::ENDS_WITH:
                $alertText = $this->translator->trans('mautic.lead_list.filter.alert.endwith');
                break;
            case OperatorOptions::CONTAINS:
                $alertText = $this->translator->trans('mautic.lead_list.filter.alert.contain');
                break;
            case OperatorOptions::LIKE:
            case OperatorOptions::NOT_LIKE:
                $alertText = $this->translator->trans('mautic.lead_list.filter.alert.like');
                break;
            default:
                return;
        }

        if (self::EMAIL_ALIAS === $event->getFieldAlias()) {
            $alertText .= ' '.$this->translator->trans('mautic.lead_list.filter.alert.email');
        }

        $event->getForm()->add('alert', AlertType::class, [
            'message'      => $alertText,
            'message_type' => 'warning',
        ]);
    }

    /**
     * @return mixed[]
     */
    private function getCampaignChoices(): array
    {
        return $this->makeChoices($this->campaignModel->getPublishedCampaigns(true), 'name', 'id');
    }

    /**
     * @return mixed[]
     */
    private function getSegmentChoices(): array
    {
        return $this->makeChoices($this->listModel->getUserLists(), 'name', 'id');
    }

    /**
     * @return mixed[]
     */
    private function getTagChoices(): array
    {
        return $this->makeChoices($this->leadModel->getTagList(), 'label', 'value');
    }

    /**
     * @return mixed[]
     */
    private function getStageChoices(): array
    {
        return $this->makeChoices($this->stageModel->getRepository()->getSimpleList(), 'label', 'value');
    }

    /**
     * @return mixed[]
     */
    private function getCategoryChoices(): array
    {
        return $this->makeChoices($this->categoryModel->getLookupResults('global', '', 300), 'title', 'id');
    }

    /**
     * @return mixed[]
     */
    private function getAssetChoices(string $filter = ''): array
    {
        return $this->makeChoices($this->assetModel->getLookupResults('asset', $filter), 'title', 'id');
    }

    /**
     * @param mixed[] $items
     *
     * @return mixed[]
     */
    private function makeChoices(array $items, string $labelName, string $keyName): array
    {
        $choices = [];

        foreach ($items as $item) {
            $choices[$item[$labelName]] = $item[$keyName];
        }

        return $choices;
    }
}
