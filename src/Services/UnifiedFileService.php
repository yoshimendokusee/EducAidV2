<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class UnifiedFileService
{
	private ApiClient $client;

	public function __construct($connection = null, string $apiBase = null)
	{
		$this->client = new ApiClient($apiBase);
	}

	public function moveToPermStorage(string $studentId, ?int $adminId = null, array $options = []): array
	{
		return $this->client->post('documents/move-to-perm-storage', [
			'student_id' => $studentId,
			'admin_id' => $adminId,
			'options' => $options,
		]);
	}

	public function archiveStudentDocuments(string $studentId): array
	{
		return $this->client->post('documents/archive', [
			'student_id' => $studentId,
		]);
	}

	public function getStudentDocuments(string $studentId, ?string $type = null, ?string $status = null): array
	{
		return $this->client->get('documents/student-documents', array_filter([
			'student_id' => $studentId,
			'type' => $type,
			'status' => $status,
		], static fn ($value) => $value !== null && $value !== ''));
	}

	public function processGradeDocumentOcr(string $studentId, string $filePath): array
	{
		return $this->client->post('documents/process-grade-ocr', [
			'student_id' => $studentId,
			'file_path' => $filePath,
		]);
	}

	public function exportStudentDocumentsZip(string $studentId, ?string $outputPath = null): array
	{
		return $this->client->get('documents/export-zip', array_filter([
			'student_id' => $studentId,
			'output_path' => $outputPath,
		], static fn ($value) => $value !== null && $value !== ''));
	}

	public function deleteStudentDocuments(string $studentId, ?array $documentIds = null): array
	{
		return $this->client->post('documents/delete', [
			'student_id' => $studentId,
			'document_ids' => $documentIds ? json_encode($documentIds) : null,
		]);
	}
}