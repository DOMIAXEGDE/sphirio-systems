import os
import uuid
import json
from pathlib import Path

# --- Core Database Classes ---

class Context:
    """
    Represents a single table or 'context' within the database.
    Manages entries, which are stored as individual .txt files.
    """
    def __init__(self, db_instance, name):
        """
        Initializes a Context instance.

        Args:
            db_instance: The parent Database object.
            name: The name of this context (e.g., 'users', 'products').
        """
        self._db = db_instance
        self._name = name
        self._path = self._db.base_path / self._name
        self._path.mkdir(exist_ok=True)
        print(f"Context '{self._name}' initialized at {self._path}")

    @property
    def name(self):
        """Returns the name of the context."""
        return self._name

    def _get_entry_path(self, entry_id):
        """Helper to get the full file path for an entry."""
        return self._path / f"{entry_id}.txt"

    def add_entry(self, data):
        """
        Adds a new entry to the context.

        Args:
            data (dict): A dictionary representing the entry. The values can be
                         multi-lined and contain tab characters.

        Returns:
            str: The unique ID of the new entry, or None if a duplicate was
                 found and not allowed.
        """
        if not isinstance(data, dict) or not data:
            print("Error: Data must be a non-empty dictionary.")
            return None

        # Check for duplicates if the global setting is disabled
        if not self._db.allow_duplicates:
            for existing_entry_data in self._db.get_all_entries():
                # Simple check for identical dictionaries
                if existing_entry_data == data:
                    print("Error: Duplicate entry detected and disallowed.")
                    return None

        entry_id = str(uuid.uuid4())
        entry_path = self._get_entry_path(entry_id)

        try:
            # We serialize the dictionary to a JSON string for simple storage.
            # This handles multi-line strings and tab characters automatically.
            json_string = json.dumps(data, indent=4)
            entry_path.write_text(json_string, encoding='utf-8')
            print(f"Successfully added entry with ID '{entry_id}' to context '{self.name}'.")
            return entry_id
        except Exception as e:
            print(f"Error adding entry: {e}")
            return None

    def get_entry(self, entry_id):
        """
        Retrieves a single entry by its unique ID.

        Args:
            entry_id (str): The unique ID of the entry.

        Returns:
            dict or None: The entry data if found, otherwise None.
        """
        entry_path = self._get_entry_path(entry_id)
        if not entry_path.is_file():
            print(f"Error: Entry with ID '{entry_id}' not found in context '{self.name}'.")
            return None

        try:
            json_string = entry_path.read_text(encoding='utf-8')
            return json.loads(json_string)
        except Exception as e:
            print(f"Error reading entry '{entry_id}': {e}")
            return None

    def update_entry(self, entry_id, new_data):
        """
        Updates an existing entry with new data.

        Args:
            entry_id (str): The unique ID of the entry to update.
            new_data (dict): The new data to replace the existing entry with.

        Returns:
            bool: True if the update was successful, otherwise False.
        """
        entry_path = self._get_entry_path(entry_id)
        if not entry_path.is_file():
            print(f"Error: Cannot update. Entry with ID '{entry_id}' not found.")
            return False

        try:
            json_string = json.dumps(new_data, indent=4)
            entry_path.write_text(json_string, encoding='utf-8')
            print(f"Successfully updated entry '{entry_id}'.")
            return True
        except Exception as e:
            print(f"Error updating entry '{entry_id}': {e}")
            return False

    def delete_entry(self, entry_id):
        """
        Deletes an entry and its corresponding file.

        Args:
            entry_id (str): The unique ID of the entry to delete.

        Returns:
            bool: True if the deletion was successful, otherwise False.
        """
        entry_path = self._get_entry_path(entry_id)
        if not entry_path.is_file():
            print(f"Error: Cannot delete. Entry with ID '{entry_id}' not found.")
            return False

        try:
            os.remove(entry_path)
            print(f"Successfully deleted entry '{entry_id}'.")
            return True
        except Exception as e:
            print(f"Error deleting entry '{entry_id}': {e}")
            return False

    def get_all_entries(self):
        """
        Retrieves all entries from the context.

        Returns:
            list: A list of dictionaries representing all entries.
        """
        entries = []
        for file_path in self._path.glob("*.txt"):
            entry = self.get_entry(file_path.stem)
            if entry:
                entries.append(entry)
        return entries


class Database:
    """
    Manages the collection of all contexts and global database settings.
    """
    def __init__(self, base_path="./database", allow_duplicates=True):
        """
        Initializes the Database instance.

        Args:
            base_path (str): The base directory for storing all data.
            allow_duplicates (bool): Global flag to allow/disallow duplicate
                                     entries across all contexts.
        """
        self.base_path = Path(base_path)
        self.base_path.mkdir(exist_ok=True)
        self._contexts = {}
        self.allow_duplicates = allow_duplicates
        print(f"Database initialized at {self.base_path}. Allow duplicates: {self.allow_duplicates}")
        # Load existing contexts from the file system
        for directory in self.base_path.iterdir():
            if directory.is_dir():
                self._contexts[directory.name] = Context(self, directory.name)

    def set_allow_duplicates(self, flag):
        """
        Toggles the global duplicate value allowance setting.

        Args:
            flag (bool): The new value for the flag.
        """
        self.allow_duplicates = flag
        print(f"Duplicate value allowance set to: {self.allow_duplicates}")

    def create_context(self, context_name):
        """
        Creates a new context (table) in the database.

        Args:
            context_name (str): The name of the new context.
        """
        if context_name in self._contexts:
            print(f"Error: Context '{context_name}' already exists.")
            return
        self._contexts[context_name] = Context(self, context_name)

    def get_context(self, context_name):
        """
        Retrieves a context object by name.

        Args:
            context_name (str): The name of the context.

        Returns:
            Context or None: The context object if found, otherwise None.
        """
        return self._contexts.get(context_name)

    def delete_context(self, context_name):
        """
        Deletes a context and all of its data.

        Args:
            context_name (str): The name of the context to delete.
        """
        if context_name not in self._contexts:
            print(f"Error: Context '{context_name}' not found.")
            return

        context_path = self.base_path / context_name
        try:
            for file_path in context_path.glob("*.txt"):
                os.remove(file_path)
            os.rmdir(context_path)
            del self._contexts[context_name]
            print(f"Successfully deleted context '{context_name}'.")
        except Exception as e:
            print(f"Error deleting context '{context_name}': {e}")

    def get_all_entries(self):
        """
        Retrieves all entries from all contexts. Used for global duplicate checks.

        Returns:
            list: A list of dictionaries representing all entries.
        """
        all_entries = []
        for context in self._contexts.values():
            all_entries.extend(context.get_all_entries())
        return all_entries


# --- Example Usage ---

if __name__ == "__main__":
    # Clean up from previous runs for a fresh start
    import shutil
    if os.path.exists("./database"):
        shutil.rmtree("./database")

    # 1. Initialize the database with duplicate allowance enabled by default
    db = Database()

    # 2. Create two contexts
    db.create_context("users")
    db.create_context("products")

    # Get the context objects
    users_context = db.get_context("users")
    products_context = db.get_context("products")

    if users_context and products_context:
        print("\n--- Adding entries (duplicates allowed) ---")
        user_data1 = {
            "name": "Alice Johnson",
            "email": "alice@example.com",
            "bio": "Software developer with a passion for gaming.\n\tI also enjoy hiking.",
            "age": 30
        }
        user_id1 = users_context.add_entry(user_data1)

        # Add an identical entry to test the duplicate check later
        user_data2 = {
            "name": "Alice Johnson",
            "email": "alice@example.com",
            "bio": "Software developer with a passion for gaming.\n\tI also enjoy hiking.",
            "age": 30
        }
        user_id2 = users_context.add_entry(user_data2) # This will succeed

        product_data1 = {
            "name": "Wireless Mouse",
            "sku": "WM-456",
            "description": "Ergonomic design for long hours.\nFeatures a smooth scroll wheel."
        }
        products_context.add_entry(product_data1)

        # 3. Toggle duplicate allowance to False
        print("\n--- Toggling duplicate allowance to False ---")
        db.set_allow_duplicates(False)

        # 4. Try to add the identical entry again (this should fail)
        print("\n--- Attempting to add duplicate entry (disallowed) ---")
        failed_id = users_context.add_entry(user_data2) # This will fail
        print(f"Attempt to add duplicate entry returned ID: {failed_id}")

        # 5. Add a different entry (this should succeed)
        print("\n--- Adding a unique entry ---")
        user_data3 = {
            "name": "Bob Smith",
            "email": "bob@example.com",
            "bio": "Data scientist who loves camping."
        }
        user_id3 = users_context.add_entry(user_data3)

        # 6. Retrieve and display an entry
        print("\n--- Retrieving an entry ---")
        if user_id1:
            retrieved_user = users_context.get_entry(user_id1)
            print(f"Retrieved Entry (ID: {user_id1}):")
            print(json.dumps(retrieved_user, indent=2))
        
        # 7. Update an entry
        print("\n--- Updating an entry ---")
        if user_id3:
            users_context.update_entry(user_id3, {"name": "Robert Smith", "email": "robert.s@example.com"})
            updated_user = users_context.get_entry(user_id3)
            print(f"Updated Entry (ID: {user_id3}):")
            print(json.dumps(updated_user, indent=2))
        
        # 8. Delete an entry
        print("\n--- Deleting an entry ---")
        if user_id2:
            users_context.delete_entry(user_id2)
        
        # 9. Get all entries
        print("\n--- Getting all entries in the 'users' context ---")
        all_users = users_context.get_all_entries()
        print(f"Number of entries in 'users': {len(all_users)}")
        for user in all_users:
            print(json.dumps(user, indent=2))
            
        print("\n--- Getting all entries in the 'products' context ---")
        all_products = products_context.get_all_entries()
        print(f"Number of entries in 'products': {len(all_products)}")
        for product in all_products:
            print(json.dumps(product, indent=2))
