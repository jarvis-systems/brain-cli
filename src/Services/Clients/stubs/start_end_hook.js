
const options = {
    hookUrlStart: "{{ START_HOOK_URL }}",
    hookUrlStop: "{{ STOP_HOOK_URL }}",
    sessionId: null,
    idleTimeout: null,
    idleDelay: 500,
};

const isValidUrl = (value) => {
    if (typeof value !== 'string' || value.trim() === '') return false;
    try {
        const u = new URL(value);
        return u.protocol === 'http:' || u.protocol === 'https:';
    } catch {
        return false;
    }
};

function sessionToUUID(sessionId) {
    const hash = Bun.hash(sessionId)
    const hashHex = hash.toString(16).padStart(16, "0")

    const fullHex = (hashHex + hashHex).slice(0, 32)
    return [
        fullHex.slice(0, 8),
        fullHex.slice(8, 12),
        fullHex.slice(12, 16),
        fullHex.slice(16, 20),
        fullHex.slice(20, 32),
    ].join("-")
}

function getSessionIDFromEvent(event) {
    const sessionID = event.properties.sessionID
    if (typeof sessionID === "string" && sessionID.length > 0) {
        return sessionToUUID(sessionID)
    }
    return null
}

async function handleEvent($, hookUrl, sessionID) {
    if (isValidUrl(hookUrl)) {
        try {
            await $`curl -s "${hookUrl}&sessionId=${sessionID}"`;
        } catch (e) {

        }
    }
}

export const StartEndHookPlugin = async ({ project, client, $, directory, worktree }) => {
    return {
        event: async ({ event }) => {
            // Start: перша головна сесія
            if (event.type === "session.created" && !options.sessionId) {
                const info = event.properties.info;
                const sessionID = sessionToUUID(info.id);
                if (sessionID) {
                    const isChild = !!info.parentID;
                    if (!isChild) {
                        options.sessionId = sessionID;
                        await handleEvent($, options.hookUrlStart, sessionID);
                    }
                }
            }

            // session.status - надійніший сигнал стану
            if (event.type === "session.status" && options.sessionId) {
                const sessionID = getSessionIDFromEvent(event);
                if (sessionID && options.sessionId === sessionID) {
                    const status = event.properties.status;

                    // busy = агент працює (включаючи thinking) - скасувати timeout
                    if (status?.type === "busy") {
                        if (options.idleTimeout) {
                            clearTimeout(options.idleTimeout);
                            options.idleTimeout = null;
                        }
                    }

                    // idle = агент дійсно чекає на input - debounce
                    if (status?.type === "idle") {
                        if (options.idleTimeout) {
                            clearTimeout(options.idleTimeout);
                        }
                        options.idleTimeout = setTimeout(async () => {
                            await handleEvent($, options.hookUrlStop, sessionID);
                            options.idleTimeout = null;
                        }, options.idleDelay);
                    }
                }
            }
        },
    }
}
