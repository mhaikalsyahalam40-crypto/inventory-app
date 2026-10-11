
<?php

function validateRequired(
    array $data,
    array $fields
): array {
    $errors = [];

    foreach ($fields as $field) {
        if (
            !isset($data[$field]) ||
            trim((string) $data[$field]) === ''
        ) {
            $errors[$field] = 'Bagian ini wajib diisi.';
        }
    }

    return $errors;
}

function validateNonNegativeNumber(
    $value,
    string $fieldName
): array {
    if (!is_numeric($value) || (float) $value < 0) {
        return [
            $fieldName => 'Nilai tidak boleh negatif.'
        ];
    }

    return [];
}

function validatePositiveInteger(
    $value,
    string $fieldName
): array {
    $quantity = filter_var(
        $value,
        FILTER_VALIDATE_INT
    );

    if ($quantity === false || $quantity <= 0) {
        return [
            $fieldName => 'Jumlah harus berupa bilangan bulat lebih dari nol.'
        ];
    }

    return [];
}
