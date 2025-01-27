<?php
session_start();
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Signin Upload</title>
    <link rel="icon" href="favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <link href="signin.css" rel="stylesheet">
    <style>
        #progress-container {
            width: 100%;
            background-color: #f3f3f3;
            border: 1px solid #ccc;
            margin: 10px 0;
            height: 20px;
            border-radius: 5px;
            display: none;
        }

        #progress-bar {
            height: 100%;
            width: 0%;
            background-color: #4caf50;
            text-align: center;
            color: white;
            border-radius: 5px;
        }

        #retval {
            margin-top: 10px;
        }
    </style>
</head>

<body class="text-center">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="mx-1 mt-1 card rounded">
                <div class="card-body">
                    <form id="uploadForm" enctype="multipart/form-data" class="form-signin">
                        <h1 class="h3 mb-3 font-weight-normal">Please verify</h1>
                        <label for="inputPassword" class="sr-only">Password</label>
                        <input type="password" name="pwd" id="pwd" class="form-control" placeholder="Password" required>
                        <label class="btn btn-primary btn-block">
                            Browse 
                            <input type="file" name="fileToUpload" id="fileToUpload"
                                onchange="$('#upload-file-info').html(this.files[0].name)" 
                                hidden>
                        </label>
                        <p class='label label-info' id="upload-file-info"></p>

                        <div id="progress-container">
                            <div id="progress-bar"></div>
                        </div>
                        <p class='label label-info' id="retval"></p>

                        <button class="btn btn-lg btn-primary btn-block" type="submit">
                            Verify and upload
                        </button>

                        <button id="uploadAnotherBtn" class="btn btn-secondary" style="display: none;">
                           Upload another file
                        </button>

                        <p class="mt-5 mb-3 text-muted">&copy; 2025</p>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    if (isset($_SESSION['message'])) {
        echo $_SESSION['message'];
        unset($_SESSION['message']);
    }
    ?>

    <script>
        const CHUNK_SIZE = 10 * 1024 * 1024; // 10MB
        const MAX_CONCURRENT_UPLOADS = 5;   // Number of chunks to upload in parallel

        // ======= EVENT: SUBMIT FORM =======
        $('#uploadForm').on('submit', async function (e) {
            e.preventDefault();

            const fileInput = $('#fileToUpload')[0].files[0];
            const password = $('#pwd').val();

            if (!fileInput) {
                alert('Please select a file to upload.');
                return;
            }

            // UI references
            const progressBar = $('#progress-bar');
            const progressContainer = $('#progress-container');
            const retval = $('#retval');

            // Reset / Show progress
            progressBar.css('width', '0%').text('');
            progressBar.css('background-color', '#4caf50');
            progressContainer.show();

            retval.text('');
            $('#uploadAnotherBtn').hide();

            // (Optional) Time-remaining message
            let timeRemainingElement = $('#time-remaining');
            if (timeRemainingElement.length === 0) {
                timeRemainingElement = $('<p id="time-remaining" style="margin-top: 10px;">Estimated time remaining: Calculating...</p>');
                progressContainer.after(timeRemainingElement);
            } else {
                timeRemainingElement.text('Estimated time remaining: Calculating...');
            }

            // 1) Split file into chunks
            const totalChunks = Math.ceil(fileInput.size / CHUNK_SIZE);

            // For (basic) progress calculation
            let totalUploadedBytes = 0;
            const startTime = Date.now();

            // 2) Create an array of tasks, each uploads a chunk
            const chunkTasks = [];
            for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                const start = chunkIndex * CHUNK_SIZE;
                const end = Math.min(fileInput.size, start + CHUNK_SIZE);
                const chunkBlob = fileInput.slice(start, end);

                // Each task is a function returning a Promise
                chunkTasks.push(() => uploadChunk(
                    chunkBlob,
                    fileInput.name,
                    chunkIndex,
                    totalChunks,
                    password
                ).then(({ bytesUploaded }) => {
                    // Update totalUploadedBytes
                    totalUploadedBytes += bytesUploaded;

                    // Update progress bar (basic, per-chunk basis)
                    const fraction = totalUploadedBytes / fileInput.size;
                    const percent = Math.round(fraction * 100);
                    progressBar.css('width', percent + '%').text(percent + '%');

                    // Update estimated time
                    const elapsedSec = (Date.now() - startTime) / 1000;
                    const speedBytesPerSec = totalUploadedBytes / elapsedSec;
                    const remainingBytes = fileInput.size - totalUploadedBytes;
                    const remainingSec = remainingBytes / speedBytesPerSec;
                    const mm = Math.floor(remainingSec / 60);
                    const ss = Math.floor(remainingSec % 60);
                    timeRemainingElement.text(
                        `Estimated time remaining: ${mm}m ${ss}s`
                    );
                }));
            }

            try {
                // 3) Upload all chunks with concurrency limit
                await runLimitedConcurrency(chunkTasks, MAX_CONCURRENT_UPLOADS);

                // 4) Once ALL chunks are uploaded, tell the server to assemble
                retval.text('All chunks uploaded. Now assembling on server...');
                await assembleFile(fileInput.name, totalChunks, password);

                // 5) Final success
                progressBar.css('width', '100%').text('100%');
                retval.text('File uploaded and assembled successfully.');
                timeRemainingElement.text('Upload complete.');
                $('#uploadAnotherBtn').show();

            } catch (err) {
                // If any chunk or the assemble step fails, show the error
                console.error(err);
                progressBar.css('background-color', '#f44336'); // red bar
                progressBar.css('width', '0%').text('');
                retval.text(err.message || 'An error occurred.');
            }
        });

        // ======= UPLOAD ONE CHUNK =======
        async function uploadChunk(chunkBlob, fileName, chunkIndex, totalChunks, password) {
            // Build the FormData
            const formData = new FormData();
            formData.append('fileChunk', chunkBlob);
            formData.append('fileName', fileName);
            formData.append('chunkIndex', chunkIndex);
            formData.append('totalChunks', totalChunks);
            formData.append('pwd', password);

            const response = await $.ajax({
                url: 'upload.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
            });

            if (response.status === 'error') {
                throw new Error(response.message || 'Chunk upload failed.');
            }

            // Return how many bytes we just uploaded
            return { bytesUploaded: chunkBlob.size };
        }

        // ======= FINAL ASSEMBLE REQUEST =======
        async function assembleFile(fileName, totalChunks, password) {
            const response = await $.ajax({
                url: 'upload.php',
                type: 'POST',
                data: {
                    fileName: fileName,
                    totalChunks: totalChunks,
                    pwd: password,
                    assemble: '1'  // tells upload.php to do final assembly
                },
                dataType: 'json'
            });

            if (response.status === 'error') {
                throw new Error(response.message || 'Assembly failed.');
            }
            // success => do nothing special
        }

        // ======= RUN CONCURRENCY LIMIT =======
        async function runLimitedConcurrency(tasks, concurrency) {
            let index = 0;           // which task we're up to
            const results = [];      // store results for each task

            // Worker function: runs tasks until none remain
            async function worker() {
                while (index < tasks.length) {
                    const currentIndex = index++;
                    // Each task is a function returning a Promise
                    const task = tasks[currentIndex];
                    results[currentIndex] = await task();
                }
            }

            // Start 'concurrency' workers in parallel
            const workers = [];
            for (let i = 0; i < concurrency; i++) {
                workers.push(worker());
            }

            // Wait until all workers are done
            await Promise.all(workers);
            return results;
        }

        // ======= RESET UI =======
        function resetUI() {
            // Clear the file input
            $('#fileToUpload').val('');

            // Clear the password field
            $('#pwd').val('');

            // Clear the displayed file name
            $('#upload-file-info').html('');

            // Reset the progress bar
            const progressBar = $('#progress-bar');
            const progressContainer = $('#progress-container');
            progressBar.css('width', '0%')
                       .css('background-color', '#4caf50')
                       .text('');
            progressContainer.hide();

            // Clear messages
            $('#retval').text('');
            $('#time-remaining').text('');

            // Hide "Upload another" again
            $('#uploadAnotherBtn').hide();
        }

        // Click: Upload Another File
        $('#uploadAnotherBtn').on('click', function () {
            // Hide this button
            $(this).hide();
            // Reset UI for a new upload
            resetUI();
        });
    </script>

</body>
</html>
