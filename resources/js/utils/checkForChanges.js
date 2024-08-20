import _ from "lodash"; // Import lodash for deep comparison

function checkForChanges(initialBook, updatedBook) {
    // Helper function to compare values, including deep comparison for arrays and objects
    function hasChanged(initialValue, updatedValue) {
        if (Array.isArray(initialValue) && Array.isArray(updatedValue)) {
            // Use lodash's isEqual for deep comparison of arrays
            return !_.isEqual(initialValue, updatedValue);
        }

        if (
            typeof initialValue === "object" &&
            typeof updatedValue === "object"
        ) {
            // Use lodash's isEqual for deep comparison of objects
            return !_.isEqual(initialValue, updatedValue);
        }

        // For primitive values, direct comparison
        return initialValue !== updatedValue;
    }

    // Filter and map to get the keys with changes and their new values
    const changes = Object.keys(initialBook)
        .filter((key) => hasChanged(initialBook[key], updatedBook[key]))
        .map((key) => ({
            key,
            newValue: updatedBook[key],
        }));

    return changes;
}

export default checkForChanges;
