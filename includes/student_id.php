<?php

require_once __DIR__ . '/db.php';

/**
 * Maps a prefix to its base group digit:
 *   0 = พระ
 *   1 = สิกขามานา / สามเณร / สามเณรี / แม่ชี
 *   2 = นาย / นาง / นางสาว / อื่นๆ (share one running count, with overflow rollover)
 */
function prefixGroup(string $prefix): int
{
    return match ($prefix) {
        'พระ' => 0,
        'สิกขามานา', 'สามเณร', 'สามเณรี', 'แม่ชี' => 1,
        default => 2,
    };
}

/**
 * Generates the next student_no for a batch+prefix group.
 *
 * Format: {batch_no}{group_digit}{2-digit seq}. Group 2 (นาย/นาง/นางสาว/อื่นๆ) rolls its
 * group digit from 2 up through 9 once it passes 99 students in a batch (digits 3-9 are
 * otherwise unused). Groups 0 and 1 have no rollover — if either exceeds 99 in one batch,
 * the UNIQUE constraint on students.student_no will reject the collision and the admin
 * must assign an ID manually.
 */
function generateStudentNo(mysqli $mysqli, int $batchId, int $batchNo, string $prefix): string
{
    $group = prefixGroup($prefix);

    $mysqli->begin_transaction();

    $stmt = $mysqli->prepare(
        'SELECT last_seq FROM student_id_sequences WHERE batch_id = ? AND prefix_group = ? FOR UPDATE'
    );
    $stmt->bind_param('ii', $batchId, $group);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        $lastSeq = 0;
        $insert = $mysqli->prepare(
            'INSERT INTO student_id_sequences (batch_id, prefix_group, last_seq) VALUES (?, ?, 0)'
        );
        $insert->bind_param('ii', $batchId, $group);
        $insert->execute();
        $insert->close();
    } else {
        $lastSeq = (int) $row['last_seq'];
    }

    $nextSeq = $lastSeq + 1;

    $update = $mysqli->prepare(
        'UPDATE student_id_sequences SET last_seq = ? WHERE batch_id = ? AND prefix_group = ?'
    );
    $update->bind_param('iii', $nextSeq, $batchId, $group);
    $update->execute();
    $update->close();

    $mysqli->commit();

    $groupDigit = $group === 2 ? 2 + intdiv($nextSeq - 1, 99) : $group;
    $seqInBucket = (($nextSeq - 1) % 99) + 1;

    return $batchNo . $groupDigit . str_pad((string) $seqInBucket, 2, '0', STR_PAD_LEFT);
}
