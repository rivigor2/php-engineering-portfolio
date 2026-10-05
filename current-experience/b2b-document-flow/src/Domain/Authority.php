<?php

declare(strict_types=1);

namespace Portfolio\DocumentFlow\Domain;

/** Inputs are verified claims supplied by an external signature/authority adapter. */
final readonly class Authority
{
    public function __construct(
        public string $employeeId,
        public string $organizationId,
        public string $certificateOwnerId,
        public ?string $powerOfAttorneyStatus,
        public bool $certificateVerified = false,
        public bool $directRepresentationVerified = false,
        public bool $delegationBoundToSignerAndOrganization = false,
        public bool $documentActionWithinGrantedScope = false,
    ) {
    }

    public function signingDecision(string $expectedOrganizationId): AuthorityDecision
    {
        if ($this->organizationId !== $expectedOrganizationId) {
            return new AuthorityDecision(false, 'organization_mismatch');
        }
        if ($this->employeeId === '' || $this->certificateOwnerId !== $this->employeeId
            || !$this->certificateVerified) {
            return new AuthorityDecision(false, 'signer_identity_not_verified');
        }
        if (!$this->documentActionWithinGrantedScope) {
            return new AuthorityDecision(false, 'document_action_not_authorized');
        }
        if ($this->directRepresentationVerified) {
            return new AuthorityDecision(true, 'direct_representation_verified');
        }
        if ($this->powerOfAttorneyStatus === 'valid' && $this->delegationBoundToSignerAndOrganization) {
            return new AuthorityDecision(true, 'delegated_authority_verified');
        }
        return new AuthorityDecision(false, 'authority_not_confirmed');
    }
}
