<?php

namespace App\Enums;

enum PermissionKey: string
{
    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdateDetails = 'projects.update-details';
    case ProjectsUpdateLines = 'projects.update-lines';
    case RevisionsCreate = 'revisions.create';
    case ProjectHistoryView = 'project-history.view';
    case ActivityLogView = 'activity-log.view';
    case ValidationView = 'validation.view';
    case ValidationRun = 'validation.run';
    case ValidationUpdateLines = 'validation.update-lines';
    case ValidationFlagLines = 'validation.flag-lines';
    case ValidationMergeLines = 'validation.merge-lines';
    case ValidationApproveLines = 'validation.approve-lines';
    case RevisionsApprove = 'revisions.approve';
    case OutputView = 'output.view';
    case OutputProduceUnpricedSchedule = 'output.produce-unpriced-schedule';
    case OutputProducePricedSchedule = 'output.produce-priced-schedule';
    case OutputProduceQuote = 'output.produce-quote';
    case OutputManageDocumentPacks = 'output.manage-document-packs';
    case OutputProduceDocumentPacks = 'output.produce-document-packs';
    case QuoteApprovalRequest = 'quote-approval.request';
    case PricingView = 'pricing.view';
    case PricingUpdate = 'pricing.update';
    case ProductsView = 'products.view';
    case ProductsImport = 'products.import';
    case SalesforceView = 'salesforce.view';
    case SalesforceManagePush = 'salesforce.manage-push';
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';
    case PermissionsManage = 'permissions.manage';

    public function label(): string
    {
        return match ($this) {
            self::ProjectsView => 'View projects',
            self::ProjectsCreate => 'Create projects',
            self::ProjectsUpdateDetails => 'Edit project details',
            self::ProjectsUpdateLines => 'Edit project areas / line items',
            self::RevisionsCreate => 'Create project revisions',
            self::ProjectHistoryView => 'View project history',
            self::ActivityLogView => 'View global history',
            self::ValidationView => 'View validation page',
            self::ValidationRun => 'Run validation',
            self::ValidationUpdateLines => 'Edit validation line items',
            self::ValidationFlagLines => 'Flag validation line items',
            self::ValidationMergeLines => 'Merge validation line items',
            self::ValidationApproveLines => 'Approve validation line items',
            self::RevisionsApprove => 'Approve and lock project revision',
            self::OutputView => 'View output page',
            self::OutputProduceUnpricedSchedule => 'Produce unpriced schedule',
            self::OutputProducePricedSchedule => 'Produce priced schedule',
            self::OutputProduceQuote => 'Produce quote',
            self::OutputManageDocumentPacks => 'Manage document packs',
            self::OutputProduceDocumentPacks => 'Produce document packs',
            self::QuoteApprovalRequest => 'Request quote approval',
            self::PricingView => 'View prices',
            self::PricingUpdate => 'Edit prices',
            self::ProductsView => 'View products list page',
            self::ProductsImport => 'Import / fetch products',
            self::SalesforceView => 'View Salesforce projects list page',
            self::SalesforceManagePush => 'Manage Salesforce push switch',
            self::UsersView => 'View users list page',
            self::UsersCreate => 'Create users',
            self::UsersUpdate => 'Edit users',
            self::UsersDelete => 'Delete users',
            self::PermissionsManage => 'Manage groups / permissions',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::ProjectsView,
            self::ProjectsCreate,
            self::ProjectsUpdateDetails,
            self::ProjectsUpdateLines => 'Projects',

            self::RevisionsCreate,
            self::RevisionsApprove,
            self::ProjectHistoryView => 'Revisions',

            self::ValidationView,
            self::ValidationRun,
            self::ValidationUpdateLines,
            self::ValidationFlagLines,
            self::ValidationMergeLines,
            self::ValidationApproveLines => 'Validation',

            self::OutputView,
            self::OutputProduceUnpricedSchedule,
            self::OutputProducePricedSchedule,
            self::OutputProduceQuote,
            self::OutputManageDocumentPacks,
            self::OutputProduceDocumentPacks,
            self::QuoteApprovalRequest => 'Output',

            self::PricingView,
            self::PricingUpdate => 'Pricing',

            self::ProductsView,
            self::ProductsImport => 'Products',

            self::SalesforceView => 'Salesforce',
            self::SalesforceManagePush => 'Salesforce',
            self::ActivityLogView => 'History',

            self::UsersView,
            self::UsersCreate,
            self::UsersUpdate,
            self::UsersDelete,
            self::PermissionsManage => 'Users & Admin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PricingView => 'Global switch for price columns and price-based outputs.',
            self::PricingUpdate => 'Allows changing project line prices. Requires price visibility.',
            self::SalesforceManagePush => 'Allows pausing and resuming outbound Salesforce writes while keeping Salesforce project import/search available.',
            self::PermissionsManage => 'Allows managing permission groups and viewing the permission catalogue.',
            default => $this->label(),
        };
    }

    /**
     * @return array<string, array{label: string, description: string|null, permissions: array<int, self>}>
     */
    public static function defaultGroups(): array
    {
        return [
            'admin' => [
                'label' => 'Admin',
                'description' => 'Everything unrestricted.',
                'permissions' => self::cases(),
            ],
            'user' => [
                'label' => 'User',
                'description' => 'Project entry and unpriced schedule access.',
                'permissions' => [
                    self::ProjectsView,
                    self::ProjectsCreate,
                    self::ProjectsUpdateDetails,
                    self::ProjectsUpdateLines,
                    self::RevisionsCreate,
                    self::ProjectHistoryView,
                    self::OutputView,
                    self::OutputProduceUnpricedSchedule,
                    self::OutputManageDocumentPacks,
                    self::OutputProduceDocumentPacks,
                ],
            ],
            'sales' => [
                'label' => 'Sales',
                'description' => 'Pricing and customer output access.',
                'permissions' => [
                    self::ProjectsView,
                    self::ProjectHistoryView,
                    self::ValidationView,
                    self::OutputView,
                    self::OutputProduceUnpricedSchedule,
                    self::OutputProducePricedSchedule,
                    self::OutputProduceQuote,
                    self::OutputManageDocumentPacks,
                    self::OutputProduceDocumentPacks,
                    self::QuoteApprovalRequest,
                    self::PricingView,
                    self::PricingUpdate,
                ],
            ],
            'technical' => [
                'label' => 'Technical',
                'description' => 'Schedule and validation access without pricing.',
                'permissions' => [
                    self::ProjectsView,
                    self::ProjectsUpdateLines,
                    self::ProjectHistoryView,
                    self::ValidationView,
                    self::ValidationRun,
                    self::ValidationUpdateLines,
                    self::ValidationFlagLines,
                    self::ValidationMergeLines,
                    self::OutputView,
                    self::OutputProduceUnpricedSchedule,
                    self::OutputManageDocumentPacks,
                    self::OutputProduceDocumentPacks,
                ],
            ],
            'manager' => [
                'label' => 'Manager',
                'description' => 'Project management, pricing, approval, and reporting access.',
                'permissions' => [
                    self::ProjectsView,
                    self::ProjectsCreate,
                    self::ProjectsUpdateDetails,
                    self::ProjectsUpdateLines,
                    self::RevisionsCreate,
                    self::ProjectHistoryView,
                    self::ActivityLogView,
                    self::ValidationView,
                    self::ValidationRun,
                    self::ValidationUpdateLines,
                    self::ValidationFlagLines,
                    self::ValidationMergeLines,
                    self::ValidationApproveLines,
                    self::RevisionsApprove,
                    self::OutputView,
                    self::OutputProduceUnpricedSchedule,
                    self::OutputProducePricedSchedule,
                    self::OutputProduceQuote,
                    self::OutputManageDocumentPacks,
                    self::OutputProduceDocumentPacks,
                    self::QuoteApprovalRequest,
                    self::PricingView,
                    self::PricingUpdate,
                    self::ProductsView,
                    self::SalesforceView,
                ],
            ],
        ];
    }
}
