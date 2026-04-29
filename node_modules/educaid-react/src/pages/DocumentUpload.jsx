import React, { useState, useRef } from 'react';
import { documentApi, enrollmentApi } from '../services/apiClient';

export default function DocumentUpload() {
  const [documents, setDocuments] = useState({
    id_picture: null,
    enrollment_form: null,
    grades: null,
    letter_to_mayor: null,
    certificate_of_indigency: null,
  });

  const [uploading, setUploading] = useState(false);
  const [results, setResults] = useState({});
  const [errors, setErrors] = useState({});
  const fileInputRefs = {
    id_picture: useRef(),
    enrollment_form: useRef(),
    grades: useRef(),
    letter_to_mayor: useRef(),
    certificate_of_indigency: useRef(),
  };

  const documentTypes = [
    {
      id: 'id_picture',
      label: 'ID Picture',
      description: 'Valid ID with photo',
      required: true,
      accept: 'image/*,.pdf',
    },
    {
      id: 'enrollment_form',
      label: 'Enrollment Form (Detailed Account Form)',
      description: 'DAF from the University',
      required: true,
      accept: '.pdf,image/*',
    },
    {
      id: 'grades',
      label: 'Grades/Academic Records',
      description: 'Official academic records',
      required: false,
      accept: '.pdf,image/*',
    },
    {
      id: 'letter_to_mayor',
      label: 'Letter to Mayor',
      description: 'Barangay certification letter',
      required: true,
      accept: '.pdf,image/*',
    },
    {
      id: 'certificate_of_indigency',
      label: 'Certificate of Indigency',
      description: 'From barangay/municipality',
      required: true,
      accept: '.pdf,image/*',
    },
  ];

  const handleFileSelect = (docType) => {
    const file = fileInputRefs[docType].current?.files?.[0];
    if (file) {
      setDocuments({
        ...documents,
        [docType]: file,
      });
      // Clear previous error for this field
      setErrors({
        ...errors,
        [docType]: null,
      });
    }
  };

  const handleUpload = async () => {
    setUploading(true);
    setResults({});
    setErrors({});

    const studentId = sessionStorage.getItem('student_id');
    const newResults = {};
    const newErrors = {};

    for (const [docType, file] of Object.entries(documents)) {
      if (!file) {
        const docDef = documentTypes.find((d) => d.id === docType);
        if (docDef?.required) {
          newErrors[docType] = 'This document is required';
        }
        continue;
      }

      try {
        // Read file as base64
        const reader = new FileReader();
        reader.onload = async () => {
          const base64Data = reader.result.split(',')[1];

          const result = await documentApi.reuploadDocument({
            student_id: studentId,
            document_type: docType,
            file_data: base64Data,
            file_name: file.name,
            mime_type: file.type,
          });

          if (result.ok && result.data.success) {
            newResults[docType] = 'Upload successful';
          } else {
            newErrors[docType] =
              result.data.message || 'Upload failed';
          }

          setResults((r) => ({ ...r, ...newResults }));
          setErrors((e) => ({ ...e, ...newErrors }));
        };
        reader.readAsDataURL(file);
      } catch (error) {
        newErrors[docType] = error.message;
        setErrors((e) => ({ ...e, [docType]: error.message }));
      }
    }

    setUploading(false);
  };

  const handleReset = () => {
    setDocuments({
      id_picture: null,
      enrollment_form: null,
      grades: null,
      letter_to_mayor: null,
      certificate_of_indigency: null,
    });
    setResults({});
    setErrors({});
    Object.values(fileInputRefs).forEach((ref) => {
      if (ref.current) ref.current.value = '';
    });
  };

  return (
    <div className="container mx-auto p-6 max-w-2xl">
      <div className="bg-white rounded-lg shadow p-6">
        <h1 className="text-3xl font-bold mb-2">Upload Documents</h1>
        <p className="text-gray-600 mb-6">
          Please upload all required documents for your application to be processed.
        </p>

        <div className="space-y-6">
          {documentTypes.map((docType) => (
            <div key={docType.id} className="border rounded-lg p-4">
              <div className="flex items-start justify-between">
                <div className="flex-1">
                  <h3 className="text-lg font-semibold">
                    {docType.label}
                    {docType.required && (
                      <span className="text-red-600 ml-2">*</span>
                    )}
                  </h3>
                  <p className="text-sm text-gray-600 mt-1">
                    {docType.description}
                  </p>

                  {/* File input */}
                  <div className="mt-3">
                    <input
                      ref={fileInputRefs[docType.id]}
                      type="file"
                      accept={docType.accept}
                      onChange={() => handleFileSelect(docType.id)}
                      className="block w-full text-sm text-gray-500
                        file:mr-4 file:py-2 file:px-4
                        file:rounded file:border-0
                        file:text-sm file:font-semibold
                        file:bg-blue-50 file:text-blue-700
                        hover:file:bg-blue-100"
                    />
                  </div>

                  {/* File status */}
                  <div className="mt-2">
                    {documents[docType.id] && (
                      <p className="text-sm text-green-600">
                        ✓ {documents[docType.id].name} ({(
                          documents[docType.id].size / 1024 / 1024
                        ).toFixed(2)} MB)
                      </p>
                    )}
                    {results[docType.id] && (
                      <p className="text-sm text-green-600">
                        ✓ {results[docType.id]}
                      </p>
                    )}
                    {errors[docType.id] && (
                      <p className="text-sm text-red-600">
                        ✗ {errors[docType.id]}
                      </p>
                    )}
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Action buttons */}
        <div className="mt-8 flex gap-4 justify-end">
          <button
            onClick={handleReset}
            disabled={uploading}
            className="px-6 py-2 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50"
          >
            Reset
          </button>
          <button
            onClick={handleUpload}
            disabled={uploading || !Object.values(documents).some((d) => d)}
            className="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
          >
            {uploading ? 'Uploading...' : 'Upload Documents'}
          </button>
        </div>

        {/* Success message */}
        {Object.values(results).length > 0 && (
          <div className="mt-6 p-4 bg-green-50 border border-green-200 rounded">
            <p className="text-green-800 font-semibold">
              ✓ Documents uploaded successfully
            </p>
            <p className="text-sm text-green-700 mt-1">
              Your documents have been submitted for review.
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
