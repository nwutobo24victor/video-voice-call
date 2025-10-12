<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP + jQuery + Tailwind Video Call</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 flex flex-col items-center justify-center min-h-screen p-4">
    <div class="bg-white p-6 rounded-2xl shadow-lg w-full max-w-3xl">
        <h1 class="text-3xl font-bold mb-6 text-gray-800 text-center">WebRTC Call Demo</h1>

        <!-- Status Display -->
        <div id="status" class="mb-4 p-3 rounded-lg text-center text-sm font-medium bg-gray-100 text-gray-700">
            Ready to start
        </div>

        <!-- Control Buttons -->
        <div class="flex justify-center gap-3 mb-6 flex-wrap">
            <button id="startCall" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-6 rounded-lg transition">
                📹 Start Video Call
            </button>
            <button id="answerCall" class="bg-green-600 hover:bg-green-700 text-white py-2 px-6 rounded-lg transition">
                📞 Answer Call
            </button>
            <button id="voiceOnly" class="bg-yellow-500 hover:bg-yellow-600 text-white py-2 px-6 rounded-lg transition">
                🎤 Voice Only
            </button>
            <button id="endCall" class="bg-red-600 hover:bg-red-700 text-white py-2 px-6 rounded-lg transition hidden">
                ❌ End Call
            </button>
            <button id="resetSignal" class="bg-gray-600 hover:bg-gray-700 text-white py-2 px-6 rounded-lg transition">
                🔄 Reset
            </button>
        </div>

        <!-- Video Display -->
        <div class="grid md:grid-cols-2 gap-4">
            <div class="relative">
                <p class="text-gray-700 font-semibold mb-2 text-center">You</p>
                <video id="localVideo" autoplay playsinline muted
                    class="rounded-lg w-full bg-black aspect-video"></video>
                <div id="localAudioIndicator"
                    class="hidden absolute top-2 left-2 bg-green-500 text-white px-2 py-1 rounded text-xs">
                    🎤 Audio Only
                </div>
            </div>
            <div class="relative">
                <p class="text-gray-700 font-semibold mb-2 text-center">Partner</p>
                <video id="remoteVideo" autoplay playsinline class="rounded-lg w-full bg-black aspect-video"></video>
                <div id="remoteWaiting"
                    class="absolute inset-0 flex items-center justify-center text-white text-sm bg-gray-800 bg-opacity-50 rounded-lg">
                    Waiting for partner...
                </div>
            </div>
        </div>

        <!-- Debug Info -->
        <div class="mt-4 p-3 bg-gray-50 rounded-lg text-xs">
            <div class="font-semibold mb-1">Debug Info:</div>
            <div id="debugInfo" class="text-gray-600 font-mono"></div>
        </div>

        <!-- Instructions -->
        <div class="mt-6 p-4 bg-blue-50 rounded-lg text-sm text-gray-700">
            <p class="font-semibold mb-2">Instructions:</p>
            <ol class="list-decimal list-inside space-y-1">
                <li><strong>Caller:</strong> Click "Start Video Call" or "Voice Only"</li>
                <li><strong>Receiver:</strong> Click "Answer Call" on another device/tab</li>
                <li>If stuck, click "Reset" on both sides and try again</li>
            </ol>
        </div>
    </div>

    <script>
    let peer, localStream, voiceOnly = false,
        pollInterval;
    let isAnswerer = false;
    let processedCandidates = new Set();

    const config = {
        iceServers: [{
                urls: 'stun:stun.l.google.com:19302'
            },
            {
                urls: 'stun:stun1.l.google.com:19302'
            }
        ]
    };

    function updateStatus(msg, type = 'info') {
        const colors = {
            info: 'bg-blue-100 text-blue-800',
            success: 'bg-green-100 text-green-800',
            error: 'bg-red-100 text-red-800',
            warning: 'bg-yellow-100 text-yellow-800'
        };
        $('#status').removeClass().addClass(`mb-4 p-3 rounded-lg text-center text-sm font-medium ${colors[type]}`).text(
            msg);
        console.log(`[${type.toUpperCase()}] ${msg}`);
    }

    function updateDebug(info) {
        $('#debugInfo').text(info);
    }

    async function init(isCaller) {
        try {
            isAnswerer = !isCaller;
            processedCandidates.clear();

            updateStatus('Initializing connection...', 'info');
            updateDebug(`Role: ${isCaller ? 'Caller' : 'Answerer'}`);

            peer = new RTCPeerConnection(config);

            // Handle incoming tracks
            peer.ontrack = e => {
                console.log('Received remote track:', e.track.kind);
                const remoteVideo = $('#remoteVideo')[0];
                if (remoteVideo.srcObject !== e.streams[0]) {
                    remoteVideo.srcObject = e.streams[0];
                    $('#remoteWaiting').hide();
                    updateStatus('Connected! Receiving partner video', 'success');
                }
            };

            // Handle ICE candidates
            peer.onicecandidate = e => {
                if (e.candidate) {
                    console.log('Sending ICE candidate:', e.candidate.candidate);
                    send('candidate', e.candidate);
                }
            };

            // Monitor connection state
            peer.oniceconnectionstatechange = () => {
                const state = peer.iceConnectionState;
                updateDebug(`ICE State: ${state}, Gathering: ${peer.iceGatheringState}`);
                console.log('ICE Connection State:', state);

                if (state === 'connected' || state === 'completed') {
                    updateStatus('Call connected successfully!', 'success');
                } else if (state === 'failed') {
                    updateStatus('Connection failed. Click Reset and try again.', 'error');
                } else if (state === 'disconnected') {
                    updateStatus('Connection disconnected', 'warning');
                }
            };

            // Get user media
            const constraints = voiceOnly ? {
                audio: true
            } : {
                video: true,
                audio: true
            };
            localStream = await navigator.mediaDevices.getUserMedia(constraints);
            $('#localVideo')[0].srcObject = localStream;

            // Add tracks to peer connection
            localStream.getTracks().forEach(track => {
                console.log('Adding local track:', track.kind);
                peer.addTrack(track, localStream);
            });

            if (voiceOnly) {
                $('#localAudioIndicator').show();
            }

            // Start signaling
            if (isCaller) {
                updateStatus('Creating offer...', 'info');
                const offer = await peer.createOffer();
                await peer.setLocalDescription(offer);
                console.log('Offer created:', offer.type);
                send('offer', offer);
                updateStatus('Waiting for answer...', 'warning');
            } else {
                updateStatus('Waiting for offer...', 'warning');
            }

            $('#startCall, #answerCall, #voiceOnly').prop('disabled', true).addClass('opacity-50');
            $('#endCall').removeClass('hidden');

            // Start polling
            poll();

        } catch (err) {
            updateStatus(`Error: ${err.message}`, 'error');
            console.error('Init error:', err);
        }
    }

    function send(type, data) {
        $.post('signal.php', {
            type,
            data: JSON.stringify(data)
        }).done(() => {
            console.log(`Sent ${type}`);
        }).fail(() => {
            updateStatus('Failed to send signal', 'error');
        });
    }

    function poll() {
        pollInterval = setInterval(async () => {
            try {
                const res = await $.getJSON('signal.php');

                // Handle offer (answerer receives this)
                if (res.offer && isAnswerer && !peer.currentRemoteDescription) {
                    console.log('Received offer, creating answer...');
                    await peer.setRemoteDescription(new RTCSessionDescription(res.offer));
                    const answer = await peer.createAnswer();
                    await peer.setLocalDescription(answer);
                    send('answer', answer);
                    updateStatus('Answer sent, waiting for connection...', 'info');
                }

                // Handle answer (caller receives this)
                if (res.answer && !isAnswerer && !peer.currentRemoteDescription) {
                    console.log('Received answer');
                    await peer.setRemoteDescription(new RTCSessionDescription(res.answer));
                    updateStatus('Answer received, connecting...', 'info');
                }

                // Handle ICE candidates
                if (res.candidate) {
                    const candidateStr = JSON.stringify(res.candidate);
                    if (!processedCandidates.has(candidateStr) && peer.remoteDescription) {
                        processedCandidates.add(candidateStr);
                        console.log('Adding ICE candidate');
                        await peer.addIceCandidate(new RTCIceCandidate(res.candidate));
                    }
                }

            } catch (e) {
                console.error('Polling error:', e);
            }
        }, 500); // Poll every 500ms for faster response
    }

    function endCall() {
        if (localStream) {
            localStream.getTracks().forEach(track => track.stop());
        }
        if (peer) {
            peer.close();
        }
        if (pollInterval) {
            clearInterval(pollInterval);
        }
        $('#localVideo')[0].srcObject = null;
        $('#remoteVideo')[0].srcObject = null;
        $('#remoteWaiting').show();
        $('#localAudioIndicator').hide();
        $('#startCall, #answerCall, #voiceOnly').prop('disabled', false).removeClass('opacity-50');
        $('#endCall').addClass('hidden');
        updateStatus('Call ended', 'info');
        updateDebug('');
        processedCandidates.clear();
    }

    function resetSignal() {
        $.ajax({
            url: 'signal.php',
            type: 'POST',
            data: {
                type: 'reset',
                data: 'true'
            }
        }).done(() => {
            updateStatus('Signal reset. Ready to start fresh.', 'success');
            endCall();
        });
    }

    $('#startCall').click(() => {
        voiceOnly = false;
        init(true);
    });

    $('#answerCall').click(() => {
        voiceOnly = false;
        init(false);
    });

    $('#voiceOnly').click(() => {
        voiceOnly = true;
        init(true);
    });

    $('#endCall').click(endCall);
    $('#resetSignal').click(resetSignal);
    </script>
</body>

</html>