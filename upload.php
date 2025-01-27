<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) Password check (customize as needed)
    if (!isset($_POST['pwd']) || $_POST['pwd'] !== '<PASSWORD>') {
        $response = ['status' => 'error', 'message' => 'Invalid password.'];
        echo json_encode($response);
        exit; // Stop processing
    }

    // 2) Get common parameters
    $uploadDir = '/home/<USERNAME>/uploads/'; // Adjust path as needed
    $fileName  = isset($_POST['fileName']) ? $_POST['fileName'] : '';
    $finalFile = $uploadDir . $fileName;

    // Make sure uploads directory exists
    // if (!file_exists($uploadDir)) {
    //     mkdir($uploadDir, 0755, true);
    // }

    // 3) If "assemble=1" is present, do the assembly step
    if (isset($_POST['assemble']) && $_POST['assemble'] === '1') {
        // The client must also send totalChunks
        if (!isset($_POST['totalChunks'])) {
            $response = ['status' => 'error', 'message' => 'Missing totalChunks for assembly.'];
            echo json_encode($response);
            exit;
        }

        $totalChunks = (int)$_POST['totalChunks'];

        // Verify that we have all .part0 ... .partN-1
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $uploadDir . $fileName . '.part' . $i;
            if (!file_exists($chunkFile)) {
                $response = [
                    'status'  => 'error',
                    'message' => "Missing chunk file $i; cannot assemble."
                ];
                echo json_encode($response);
                exit;
            }
        }

        // If final file already exists, you may decide to remove it or error out
        if (file_exists($finalFile)) {
            // Example: error out
            $response = [
                'status'  => 'error',
                'message' => 'A file with that name already exists.'
            ];
            echo json_encode($response);
            exit;
        }

        // Create the final file
        if (($outputFile = fopen($finalFile, 'wb')) === false) {
            $response = [
                'status'  => 'error',
                'message' => 'Failed to create the final file.'
            ];
            echo json_encode($response);
            exit;
        }

        // Append each chunk in order
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $uploadDir . $fileName . '.part' . $i;
            if (($inputFile = fopen($chunkFile, 'rb')) === false) {
                fclose($outputFile);
                $response = [
                    'status'  => 'error',
                    'message' => "Failed to read chunk $i."
                ];
                echo json_encode($response);
                exit;
            }

            while ($buffer = fread($inputFile, 4096)) {
                fwrite($outputFile, $buffer);
            }

            fclose($inputFile);
            unlink($chunkFile); // Remove the chunk after we have appended it
        }
        fclose($outputFile);

        // Assembly success
        $response = ['status' => 'success', 'message' => 'File assembled successfully.'];
        echo json_encode($response);
        exit;

    } else {
        // 4) This part handles a chunk upload (no assembly yet)

        // Make sure we have chunkIndex, totalChunks, etc.
        if (!isset($_POST['chunkIndex']) || !isset($_POST['totalChunks'])) {
            $response = ['status' => 'error', 'message' => 'Missing chunkIndex or totalChunks.'];
            echo json_encode($response);
            exit;
        }

        $chunkIndex  = (int)$_POST['chunkIndex'];
        $totalChunks = (int)$_POST['totalChunks'];

        // Optional: If chunkIndex==0, check if final file already exists
        // (In case someone tries to overwrite an existing file)
        if ($chunkIndex === 0 && file_exists($finalFile)) {
            $response = [
                'status' => 'error',
                'message' => 'A file with that name already exists. Upload aborted.'
            ];
            echo json_encode($response);
            exit;
        }

        // Validate we have the chunk data in $_FILES
        if (!isset($_FILES['fileChunk']) || $_FILES['fileChunk']['error'] !== UPLOAD_ERR_OK) {
            $response = [
                'status'  => 'error',
                'message' => 'No chunk file uploaded or upload error.'
            ];
            echo json_encode($response);
            exit;
        }

        // Move the chunk to a temporary .part file
        $tempFile = $uploadDir . $fileName . '.part' . $chunkIndex;
        if (!move_uploaded_file($_FILES['fileChunk']['tmp_name'], $tempFile)) {
            $response = ['status' => 'error', 'message' => 'Failed to save file chunk.'];
            echo json_encode($response);
            exit;
        }

        // Return success for the chunk
        $response = [
            'status'  => 'success',
            'message' => "Chunk #$chunkIndex uploaded."
        ];
        echo json_encode($response);
        exit;
    }
}
