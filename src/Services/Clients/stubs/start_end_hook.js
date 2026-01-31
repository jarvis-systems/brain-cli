
const hookUrlStart = "{{ START_HOOK_URL }}";
const hookUrlStop = "{{ STOP_HOOK_URL }}";

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
        return sessionID
    }
    return null
}

async function isChildSession(client, sessionID) {
    try {
        const response = await client.session.get({ path: { id: sessionID } })
        const parentID = response.data.parentID
        return !!parentID
    } catch {
        return false
    }
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
            if (event.type === "session.created") {
                const info = event.properties.info;
                const sessionID = sessionToUUID(info.id);
                if (sessionID) {
                    const isChild = !!info.parentID;
                    if (!isChild) {
                        await handleEvent($, hookUrlStart, sessionID);
                    }
                }
            }
            if (event.type === "session.idle") {
                const sessionID = getSessionIDFromEvent(event)
                if (sessionID) {
                    const isChild = await isChildSession(client, sessionID)
                    if (!isChild) {
                        await handleEvent($, hookUrlStop, sessionID);
                    }
                }
            }
        },
    }
}
