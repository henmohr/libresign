<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Enum\SignatureFlow;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TelemetryMapper {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return array{files: int, envelopes: int, sequential_flows: int, signatures: int, signed_documents: int, completed_flows: int} */
	public function getUsage(): array {
		return [
			'files' => $this->countFiles(['node_type' => 'file']),
			'envelopes' => $this->countFiles(['node_type' => 'envelope']),
			'sequential_flows' => $this->countFiles(['signature_flow' => SignatureFlow::NUMERIC_ORDERED_NUMERIC], true),
			'signatures' => $this->countSignatures(),
			'signed_documents' => $this->countFiles([
				'node_type' => 'file',
				'status' => FileStatus::SIGNED->value,
			]),
			'completed_flows' => $this->countFiles(['status' => FileStatus::SIGNED->value], true),
		];
	}

	/** @param array<string, int|string> $filters */
	private function countFiles(array $filters, bool $rootOnly = false): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('libresign_file');
		foreach ($filters as $column => $value) {
			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		}
		if ($rootOnly) {
			$qb->andWhere($qb->expr()->isNull('parent_file_id'));
		}
		return $this->fetchCount($qb);
	}

	private function countSignatures(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('libresign_sign_request')
			->where($qb->expr()->isNotNull('signed'));
		return $this->fetchCount($qb);
	}

	public function getActiveUsers(int $since): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('login')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('lastLogin')))
			->andWhere($qb->expr()->gte('configvalue', $qb->createNamedParameter((string)$since)));
		return $this->fetchCount($qb);
	}

	private function fetchCount(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	public function getCancelledFlows(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('value')->from('libresign_telemetry')
			->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	public function incrementCancelledFlows(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('libresign_telemetry')
			->set('value', $qb->func()->add('value', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
